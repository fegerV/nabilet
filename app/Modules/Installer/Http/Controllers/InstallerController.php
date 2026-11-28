<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Http\Controllers;

use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\DatabaseInvariants;
use Nabilet\Core\Support\PublicationStatus;
use Nabilet\Modules\Core\Support\PublicId;
use Nabilet\Modules\Core\Users\Domain\UserStatus;
use Nabilet\Modules\Installer\Support\InstallKey;
use Nabilet\Modules\Installer\Support\PreinstallAppKey;
use Nabilet\Modules\Installer\Support\SetupArtifactCleaner;

/**
 * InstallerController — пошаговый установщик системы для шаред-хостинга.
 *
 * Предназначен для одноразового использования при первом запуске:
 * 1. Проверка требований сервера (PHP, расширения, права доступа)
 * 2. Настройка подключения к БД и запись .env
 * 3. Запуск миграций и сидеров
 * 4. Создание администратора (Vue-админка)
 * 5. Создание symbolic link для storage
 * 6. Блокировка повторного запуска через storage/install.lock
 *
 * После успешной установки файл storage/install.lock блокирует доступ к /install
 */
class InstallerController extends Controller
{
    /**
     * Шаблон генерируемого `.env`.
     *
     * ВАЖНО (аудит установщика B-3/B-4): этот блок — единственный источник
     * настроек после установки, и он же определяет, заработает ли система
     * «из коробки» без ручной правки файла.
     *
     *  - `QUEUE_CONNECTION=database`, а НЕ `sync`: письма и вебхуки уходят в
     *    очередь (`SendOrderNotificationJob`, `SendWebhookDeliveryJob`). При
     *    `sync` отправка выполняется прямо внутри HTTP-запроса покупателя —
     *    таймауты и деградация оформления заказа.
     *  - `CACHE_STORE`, а НЕ устаревший `CACHE_DRIVER`: `config/cache.php`
     *    читает именно `CACHE_STORE`, поэтому `CACHE_DRIVER` молча игнорировался
     *    и драйвер брался по умолчанию — конфигурация вводила в заблуждение.
     *  - `SESSION_DRIVER=database`: сессии переживают перезапуск и не зависят от
     *    каталога `storage/` на шаред-хостинге.
     *  - блок `MAIL_*` обязателен: без него билеты и сброс пароля не доставляются.
     *  - `BROADCAST_CONNECTION` (Laravel 11+), а не `BROADCAST_DRIVER`.
     */
    private const ENV_TEMPLATE = <<<'ENV'
APP_NAME={APP_NAME}
APP_ENV={APP_ENV}
APP_KEY={APP_KEY}
APP_DEBUG={APP_DEBUG}
APP_URL={APP_URL}
APP_LOCALE={APP_LOCALE}
APP_FALLBACK_LOCALE={APP_FALLBACK_LOCALE}
APP_TIMEZONE={APP_TIMEZONE}
NABILET_LOCALE={NABILET_LOCALE}
NABILET_REGION={NABILET_REGION}
NABILET_CURRENCY={NABILET_CURRENCY}

LOG_CHANNEL={LOG_CHANNEL}
LOG_LEVEL={LOG_LEVEL}

DB_CONNECTION={DB_CONNECTION}
DB_HOST={DB_HOST}
DB_PORT={DB_PORT}
DB_DATABASE={DB_DATABASE}
DB_USERNAME={DB_USERNAME}
DB_PASSWORD={DB_PASSWORD}
# Коллация обязательна: дефолт `config/database.php` (`''`) MySQL 8 отвергает
# ошибкой `Unknown collation: ''` на первом же запросе миграций (найдено
# прогоном визарда 2026-11-04: установка падала прямо на шаге migrate).
DB_CHARSET={DB_CHARSET}
DB_COLLATION={DB_COLLATION}

BROADCAST_CONNECTION=log
CACHE_STORE={CACHE_STORE}
# Хостинг без Redis (задокументированный сценарий Timeweb): стенд в БД —
# осознанный выбор. Без этого подтверждения ProductionSafetyAudit в
# production не даст приложению подняться (см. ProductionSafetyAudit).
NABILET_ALLOW_DATABASE_CACHE=true
FILESYSTEM_DISK={FILESYSTEM_DISK}
QUEUE_CONNECTION={QUEUE_CONNECTION}
SESSION_DRIVER={SESSION_DRIVER}
SESSION_LIFETIME=120

# Региональный каталог площадок (выбор заказчика на шаге установки).
NABILET_SEED_SURGUT={NABILET_SEED_SURGUT}
# Платформенный администратор назначается только владельцу, созданному мастером.
NABILET_PLATFORM_ADMINS={NABILET_PLATFORM_ADMINS}
# Обновления: только публичный ключ и адрес ленты. Приватный ключ НЕ хранится на сайте.
NABILET_UPDATE_PUBLIC_KEY={NABILET_UPDATE_PUBLIC_KEY}
NABILET_UPDATE_FEED_URL={NABILET_UPDATE_FEED_URL}

MAIL_MAILER={MAIL_MAILER}
MAIL_HOST={MAIL_HOST}
MAIL_PORT={MAIL_PORT}
MAIL_USERNAME={MAIL_USERNAME}
MAIL_PASSWORD={MAIL_PASSWORD}
MAIL_ENCRYPTION={MAIL_ENCRYPTION}
MAIL_FROM_ADDRESS={MAIL_FROM_ADDRESS}
MAIL_FROM_NAME={MAIL_FROM_NAME}

YOOKASSA_SHOP_ID={YOOKASSA_SHOP_ID}
YOOKASSA_SECRET_KEY={YOOKASSA_SECRET_KEY}
ENV;

    /**
     * Показать форму установщика или вернуть JSON с требованиями
     */
    public function show(Request $request)
    {
        // Если уже установлено — блокируем доступ
        if ($this->isInstalled()) {
            return $request->expectsJson()
                ? response()->json(['error' => [
                    'code' => 'ALREADY_INSTALLED',
                    'message' => 'Система уже установлена',
                    'details' => [],
                ]], 403)
                : redirect('/')->with('error', 'Система уже установлена');
        }

        // Ключ создаётся при первом открытии мастера, но НЕ возвращается в
        // ответе: его читает владелец из storage/install.key вне DocumentRoot.
        InstallKey::ensure();
        $requirements = $this->checkRequirements();
        $passed = $requirements['passed'];

        if ($request->expectsJson()) {
            return response()->json([
                'step' => 'requirements',
                'passed' => $passed,
                'requirements' => $requirements['details'],
                'install_key' => InstallKey::instructions(),
                'partial_install' => $this->installState(),
            ]);
        }

        return view('installer::install', [
            'passed' => $passed,
            'requirements' => $requirements['details'],
            'installKey' => InstallKey::instructions(),
            'partialInstall' => $this->installState(),
            'nextSteps' => $this->nextSteps(),
        ]);
    }

    /**
     * Обработать форму установки.
     *
     * Одноразовый ключ + неблокирующая файловая блокировка: без ключа мастер
     * может захватить посторонний посетитель, а без блокировки два параллельных
     * запроса могут одновременно писать .env и гонять миграции.
     */
    public function install(Request $request): JsonResponse
    {
        if ($this->isInstalled()) {
            return response()->json(['error' => [
                'code' => 'ALREADY_INSTALLED',
                'message' => 'Система уже установлена',
                'details' => [],
            ]], 403);
        }

        if ($denied = $this->guardInstallKey($request)) {
            return $denied;
        }

        // Блокировка взята — проверяем состояние ЕЩЁ РАЗ: другой запрос мог
        // завершить установку, пока мы ждали захвата, или отозвать ключ.
        $result = $this->withInstallLock(function () use ($request): JsonResponse {
            if ($this->isInstalled()) {
                return response()->json(['error' => [
                    'code' => 'ALREADY_INSTALLED',
                    'message' => 'Система уже установлена',
                    'details' => [],
                ]], 403);
            }
            if ($denied = $this->guardInstallKey($request)) {
                return $denied;
            }

            return $this->performInstall($request);
        });
        if ($result === null) {
            return response()->json(['error' => [
                'code' => 'INSTALL_IN_PROGRESS',
                'message' => 'Установка уже выполняется другим запросом. Повторите после его завершения.',
                'details' => [],
            ]], 409);
        }

        return $result;
    }

    /** @return JsonResponse|null Ответ-отказ или null, если ключ корректен. */
    private function guardInstallKey(Request $request): ?JsonResponse
    {
        $candidate = $request->header(InstallKey::HEADER) ?: $request->input('install_key');
        if (InstallKey::verify(is_string($candidate) ? $candidate : null)) {
            return null;
        }

        return response()->json(['error' => [
            'code' => 'INSTALL_KEY_INVALID',
            'message' => 'Неверный или отсутствующий ключ установки. Прочитайте storage/install.key через файловый менеджер или SSH и введите его в мастере.',
            'details' => InstallKey::instructions(),
        ]], 403);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function withInstallLock(callable $callback): mixed
    {
        $path = storage_path('framework/install-in-progress.lock');
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            return null;
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }

            return null;
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function performInstall(Request $request): JsonResponse
    {
        $this->writeInstallState('started');

        try {
            // Лимит PHP (по умолчанию 30 с у php-fpm на шаред-хостинге) считает
            // время работы ВНУТРИ одного запроса: миграции ~90 таблиц и bcrypt
            // в сидерах реально выходят за 30 с (найдено прогоном визарда
            // 2026-11-04: FatalError «Maximum execution time …» прямо в
            // Hashing\BcryptHasher). Установка — разовое действие, а не
            // пользовательский запрос: обнуляем лимит; если хостер запретил
            // set_time_limit, фолбэка нет — но и прежний лимит не спасал.
            @set_time_limit(0);

            $validated = $request->validate([
                'app_name' => ['required', 'string', 'max:255'],
                // production-шаблон (APP_ENV=production) против правила
                // `ProductionSafetyAudit::insecureAppUrl()`: если мастер примет
                // http://, приложение отвергнет собственный .env сразу после
                // установки и ляжет (tickets + платежи обязаны жить на HTTPS).
                // Отсекаем это НА форме, а не 500-й после неё.
                'app_url' => ['required', 'url', 'max:255', 'starts_with:https://'],
                'timezone' => ['required', 'string', 'max:64'],
                'locale' => ['required', 'string', 'in:ru,en'],
                'region' => ['nullable', 'string', 'max:120'],
                'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                'updates_feed_url' => ['nullable', 'url', 'max:2048', 'starts_with:https://'],
                'updates_public_key' => ['nullable', 'string', 'max:8192'],
                // Региональный каталог — выбор заказчика, а не свойство движка.
                // Чекбокс визарда присылает boolean; его absence означает false.
                'seed_surgut' => ['nullable', 'boolean'],
                'db_host' => ['required', 'string', 'max:255'],
                'db_port' => ['required', 'integer', 'between:1,65535'],
                'db_database' => ['required', 'string', 'max:255'],
                'db_username' => ['required', 'string', 'max:255'],
                'db_password' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'email', 'max:255'],
                'admin_password' => ['required', 'string', 'min:8', 'max:255'],
                // Почта (SMTP). Необязательна на шаге установки: если хост не
                // задан, мастер пишет `MAIL_MAILER=log`, чтобы система работала
                // без падения на первой же отправке, а SMTP можно включить позже.
                'mail_host' => ['nullable', 'string', 'max:255'],
                'mail_port' => ['nullable', 'integer', 'between:1,65535'],
                'mail_username' => ['nullable', 'string', 'max:255'],
                'mail_password' => ['nullable', 'string', 'max:255'],
                'mail_encryption' => ['nullable', 'string', 'max:10'],
                'mail_from_address' => ['nullable', 'email', 'max:255'],
                'mail_from_name' => ['nullable', 'string', 'max:255'],
                'yookassa_shop_id' => ['nullable', 'string', 'max:255'],
                'yookassa_api_key' => ['nullable', 'string', 'max:255'],
            ], [
                'app_url.starts_with' => 'APP_URL должен начинаться с https:// — приложение '
                    . 'отдаёт билеты и принимает оплату; по http оно откажется подниматься.',
            ]);

            if (! in_array($validated['timezone'], DateTimeZone::listIdentifiers(), true)) {
                throw new ValidationError(['timezone' => ['Неизвестный часовой пояс.']]);
            }
            $publicKey = trim((string) ($validated['updates_public_key'] ?? ''));
            if ($publicKey !== '' && openssl_pkey_get_public($this->normalizePublicKey($publicKey)) === false) {
                throw new ValidationError(['updates_public_key' => ['Не удалось прочитать публичный ключ обновлений (ожидается PEM или base64 DER).']]);
            }

            // Шаг 1: Проверка подключения к БД
            //
            // Порт приводится к int явно: `validate('integer')` не приводит типы,
            // а HTML-форма (без JS-визарда) присылает порт строкой — под
            // strict_types=1 это TypeError вместо установки (найдено
            // e2e-прогоном архива v1.0.1, 2026-11-13).
            $this->testDatabaseConnection(
                $validated['db_host'],
                (int) $validated['db_port'],
                $validated['db_database'],
                $validated['db_username'],
                $validated['db_password']
            );

            // MySQL 5.7 молча игнорирует CHECK — фиксируем это в журнале, который
            // показывает визард. Установку не блокируем: для ручного тестирования
            // на таком сервере система работоспособна, но без БД-инвариантов.
            $dbWarning = $this->databaseVersionWarning(
                $validated['db_host'],
                (int) $validated['db_port'],
                $validated['db_database'],
                $validated['db_username'],
                $validated['db_password'],
            );
            if ($dbWarning !== null) {
                Log::warning('Installer: ' . $dbWarning);
            }

            // Шаг 2: Запись .env файла (возвращает сгенерированный APP_KEY)
            $appKey = $this->writeEnvFile($validated);
            $this->writeInstallState('env_written');

            // Перед запуском Artisan-команд подменяем in-memory-конфиг реальными
            // учётными данными из формы. `config:clear` удаляет только кэш-файл, но
            // НЕ перечитывает Dotenv в уже загруженный репозиторий конфигурации,
            // поэтому без этой подмены `migrate`/seed уходили бы на boot-значения
            // (127.0.0.1 / forge), а не на введённую БД, и установка падала бы.
            config([
                'database.connections.mysql.host' => $validated['db_host'],
                'database.connections.mysql.port' => $validated['db_port'],
                'database.connections.mysql.database' => $validated['db_database'],
                'database.connections.mysql.username' => $validated['db_username'],
                'database.connections.mysql.password' => $validated['db_password'],
                // Коллация — та же ловушка, что креды: boot-конфиг (без .env)
                // содержит `''`, и MySQL 8 валит первый же запрос миграций
                // `Unknown collation: ''`. Значения обязаны совпадать с тем,
                // что шаблон кладёт в .env — их источником и был этот список.
                'database.connections.mysql.charset' => 'utf8mb4',
                'database.connections.mysql.collation' => 'utf8mb4_unicode_ci',
                'app.key' => $appKey,
                'app.url' => $validated['app_url'],
                // Значение в памяти должно совпадать с тем, что уехало в .env: db:seed
                // выполняется в ЭТОМ ЖЕ запросе, а Dotenv уже загружен без нового
                // флага. Без подмены каталог Сургута не загрузился бы даже при
                // включённом чекбоксе — прочитался бы boot-дефолт false.
                'nabilet.seed_surgut' => ! empty($validated['seed_surgut']),
                'app.env' => 'production',
            ]);
            DB::purge('mysql');

            // Шаг 3: Очистка кэша конфигурации
            Artisan::call('config:clear');
            Artisan::call('cache:clear');

            // Шаг 4: Запуск миграций
            $migrateExitCode = Artisan::call('migrate', ['--force' => true]);
            if ($migrateExitCode !== 0) {
                throw new \RuntimeException('Не удалось выполнить миграции базы данных');
            }
            $this->writeInstallState('migrated');

            // Шаг 5: Создание администратора и его организации
            $this->createAdminUser($validated['admin_email'], $validated['admin_password'], $validated['app_name']);
            $this->writeInstallState('admin_created');

            // Шаг 6: Создание symbolic link для storage
            try {
                Artisan::call('storage:link');
            } catch (\Throwable $e) {
                Log::warning('Installer: Не удалось создать storage:link', ['exception' => $e->getMessage()]);
                // Не блокируем установку, если symlink не работает
            }

            // Шаг 7: Обязательные сидеры. Роли/ права и системные справочники
            // не являются опциональными: без них установка НЕ завершается.
            $seedExit = Artisan::call('db:seed', ['--force' => true]);
            if ($seedExit !== 0) {
                throw new \RuntimeException('Обязательные сидеры не выполнены (exit ' . $seedExit . ')');
            }
            $this->writeInstallState('seeded');

            // Шаг 8: Генерация APP_KEY если не был указан
            if (empty(config('app.key'))) {
                Artisan::call('key:generate', ['--force' => true]);
            }

            // Шаг 9: Блокировка повторной установки и зачистка следов развёртывания.
            $removed = $this->markAsInstalled();

            Log::info('Installer: Система успешно установлена', [
                'app_name' => $validated['app_name'],
                'app_url' => $validated['app_url'],
                'db_host' => $validated['db_host'],
                'admin_email' => $validated['admin_email'],
                'removed_setup_files' => $removed,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Система успешно установлена',
                // Настоящий путь: витрина и админка — один SPA на history-
                // роутинге, `/admin` отдаёт SPA-оболочку на белом списке путей
                // (routes/web.php). До 1.2.0 здесь был hash-адрес `/#/admin`.
                'redirect' => '/admin',
                // Что именно убрали с диска после установки. Пустой список —
                // норма: в архиве этих файлов нет, они появляются только руками
                // хостера/администратора (см. SetupArtifactCleaner).
                'removed_setup_files' => $removed,
                // Итоговая диагностика: критическое ≠ предупреждение. Критические
                // находки означают, что установка НЕ может считаться готовой.
                'diagnostics' => $this->finalDiagnostics($validated),
                'next_steps' => $this->nextSteps(),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Ошибка валидации',
                'details' => ['fields' => $e->errors()],
            ]], 422);

        } catch (\Throwable $e) {
            // Состояние сохраняется ДО ответа: после обрыва PHP/перезагрузки
            // страницы владелец увидит, на каком шаге остановилась установка,
            // и сможет повторить её теми же данными (миграции идемпотентны).
            $this->writeInstallState('failed', $e->getMessage());
            Log::error('Installer: Критическая ошибка установки', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Внутреннее сообщение уходит только в лог: раньше оно
            // публиковалось клиенту и раскрывало текст драйвера БД и пути.
            return response()->json(['error' => [
                'code' => 'INSTALL_FAILED',
                'message' => 'Не удалось завершить установку. Подробности — в журнале.',
                'details' => [],
            ]], 500);
        }
    }

    /**
     * Проверить параметры подключения к БД, ничего не записывая на диск.
     *
     * Раньше кнопка «Проверить подключение» на шаге 2 была клиентской
     * имитацией (`setTimeout(…, 700)` + галочка), поэтому пользователь видел
     * «успех» при заведомо неверных кредах, а реальная проверка выполнялась
     * только внутри POST /install (аудит установщика §4 / W-3).
     */
    public function testDatabase(Request $request): JsonResponse
    {
        if ($this->isInstalled()) {
            return response()->json(['error' => [
                'code' => 'ALREADY_INSTALLED',
                'message' => 'Система уже установлена',
                'details' => [],
            ]], 403);
        }

        if ($denied = $this->guardInstallKey($request)) {
            return $denied;
        }

        try {
            $validated = $request->validate([
                'db_host' => ['required', 'string', 'max:255'],
                'db_port' => ['required', 'integer', 'between:1,65535'],
                'db_database' => ['required', 'string', 'max:255'],
                'db_username' => ['required', 'string', 'max:255'],
                'db_password' => ['required', 'string', 'max:255'],
            ]);

            $this->testDatabaseConnection(
                $validated['db_host'],
                (int) $validated['db_port'],
                $validated['db_database'],
                $validated['db_username'],
                $validated['db_password']
            );

            return response()->json([
                'success' => true,
                'message' => 'Подключение установлено',
                // Старый MySQL молча игнорирует CHECK: migration table покажет
                // «Ran», а инварианты денег/статусов не будут enforced. Показываем
                // это прямо в визарде, а не только в журнале.
                'warning' => $this->databaseVersionWarning(
                    $validated['db_host'],
                    (int) $validated['db_port'],
                    $validated['db_database'],
                    $validated['db_username'],
                    $validated['db_password'],
                ),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Заполните параметры подключения',
                'details' => ['fields' => $e->errors()],
            ]], 422);
        } catch (\Throwable $e) {
            // Сообщение PDO раскрывает драйвер и хост, но не учётные данные; на
            // шаге настройки это полезно. Точный текст остаётся в логе.
            return response()->json(['error' => [
                'code' => 'DB_CONNECTION_FAILED',
                'message' => 'Не удалось подключиться к базе данных. Проверьте хост, порт, имя БД и учётные данные.',
                'details' => [],
            ]], 422);
        }
    }

    /**
     * Проверить требования сервера
     */
    private function checkRequirements(): array
    {
        $details = [];
        $allPassed = true;

        // Проверка версии PHP.
        //
        // Требуем 8.3, а не 8.1: `composer.json` объявляет `"php": "^8.3"`, и
        // Composer platform-check в `vendor/` роняет процесс на более старой
        // версии. Раньше мастер пропускал шаг 1 на PHP 8.1/8.2, а затем падал
        // уже на `migrate` — получалась полу-установка с записанным `.env`
        // и базой на середине миграций (аудит установщика B-1).
        $phpVersion = PHP_VERSION;
        $phpRequired = '8.3';
        $phpPassed = version_compare($phpVersion, $phpRequired, '>=');
        $details['php_version'] = [
            'name' => 'Версия PHP (≥' . $phpRequired . ')',
            'passed' => $phpPassed,
            'current' => $phpVersion,
            'required' => $phpRequired,
        ];
        if (!$phpPassed) {
            $allPassed = false;
        }

        // Проверка расширений
        $extensions = ['pdo', 'mbstring', 'openssl', 'json', 'xml', 'curl', 'zip'];
        foreach ($extensions as $ext) {
            $extLoaded = extension_loaded($ext);
            $details['ext_' . $ext] = [
                'name' => 'Расширение ' . $ext,
                'passed' => $extLoaded,
                'current' => $extLoaded ? 'установлено' : 'не установлено',
                'required' => 'установлено',
            ];
            if (!$extLoaded) {
                $allPassed = false;
            }
        }

        // Проверка прав на запись.
        //
        // Корень проекта тоже в списке: именно туда пишется `.env`
        // (`writeEnvFile()`), и без права записи установка обрывалась бы уже
        // после успешного PDO-теста — «необъяснимым» 500 (аудит установщика §3).
        $writablePaths = [
            storage_path(),
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
            base_path(),
        ];

        foreach ($writablePaths as $path) {
            $isWritable = is_writable($path);
            $relativePath = str_replace(base_path(), '', $path) ?: '/';
            $details['writable_' . md5($path)] = [
                'name' => 'Права на запись: ' . $relativePath,
                'passed' => $isWritable,
                'current' => $isWritable ? 'запись разрешена' : 'запись запрещена',
                'required' => 'запись разрешена',
            ];
            if (!$isWritable) {
                $allPassed = false;
            }
        }

        // Проверка наличия .env
        $envExists = File::exists(base_path('.env'));
        $details['env_file'] = [
            'name' => 'Файл .env отсутствует',
            'passed' => !$envExists,
            'current' => $envExists ? 'существует' : 'отсутствует',
            'required' => 'отсутствует',
        ];
        // Это не блокирующее требование

        return [
            'passed' => $allPassed,
            'details' => $details,
        ];
    }

    /**
     * Тестирование подключения к БД
     */
    /**
     * Предупреждение о версии сервера БД (или null, если всё в порядке).
     *
     * CHECK исполняются начиная с MySQL 8.0.16 / MariaDB 10.2.1. На более
     * старых серверах (TimeWeb с MySQL 5.7) `ALTER TABLE ... ADD CONSTRAINT
     * ... CHECK` синтаксически принимается, но инвариант НЕ создаётся, а
     * `migrate:status` честно пишет «Ran». Без этого предупреждения установка
     * выглядит успешной, хотя защита денег, статусов и состояний билета
     * работает только на уровне PHP.
     */
    private function databaseVersionWarning(string $host, int $port, string $database, string $username, string $password): ?string
    {
        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3],
            );
            $version = trim((string) $pdo->query('select version() as srv_version')->fetchColumn());
        } catch (\Throwable) {
            return null; // подключение уже проверено отдельно; здесь только диагностика
        }

        return self::versionNeedsWarning($version);
    }

    /** Чистая проверка строки версии — владелец правила в `DatabaseInvariants`. */
    private static function versionNeedsWarning(string $version): ?string
    {
        $minimum = DatabaseInvariants::missingCheckSupport($version);
        if ($minimum === null) {
            return null;
        }

        return sprintf(
            'Внимание: сервер БД %s не исполняет CHECK-ограничения (нужен %s %s+). '
            . 'Установка завершится, но инварианты сумм, статусов и состояний билета '
            . 'будут защищены только кодом. Для тестового стенда это допустимо, '
            . 'для реальных продаж переведите аккаунт на MySQL 8.0+.',
            $version,
            DatabaseInvariants::isMariaDb($version) ? 'MariaDB' : 'MySQL',
            $minimum,
        );
    }

    private function testDatabaseConnection(string $host, int $port, string $database, string $username, string $password): void
    {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // Без явного таймаута неверные креды на 일부 хостах висят до
                // дефолтного сетевого таймаута: визард выглядит зависшим.
                \PDO::ATTR_TIMEOUT => 3,
            ];

            new \PDO($dsn, $username, $password, $options);

            Log::info('Installer: Подключение к БД успешно', ['host' => $host, 'database' => $database]);

        } catch (\PDOException $e) {
            Log::error('Installer: Не удалось подключиться к БД', [
                'host' => $host,
                'database' => $database,
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Не удалось подключиться к базе данных. Проверьте параметры подключения. ' .
                'На Timeweb хост БД может отличаться от localhost — используйте значение из панели управления.'
            );
        }
    }

    /**
     * Записать .env файл
     */
    private function writeEnvFile(array $data): string
    {
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $envContent = $this->buildEnvContent($data, $appKey);

        $envPath = base_path('.env');

        if (!File::put($envPath, $envContent)) {
            throw new \RuntimeException('Не удалось записать файл .env. Проверьте права на запись в корень проекта.');
        }

        Log::info('Installer: Файл .env успешно создан', [
            'mail_mailer' => str_contains($envContent, 'MAIL_MAILER=log') ? 'log' : 'smtp',
        ]);

        return $appKey;
    }

    /**
     * Собрать содержимое `.env` из шаблона и данных мастера.
     *
     * Вынесено отдельно от записи на диск, чтобы результат можно было
     * проверить тестом, не трогая реальный `.env` установки.
     */
    private function buildEnvContent(array $data, string $appKey): string
    {
        $mailHost = trim((string) ($data['mail_host'] ?? ''));
        $mailUsername = trim((string) ($data['mail_username'] ?? ''));
        $mailPassword = (string) ($data['mail_password'] ?? '');
        $mailEncryption = strtolower(trim((string) ($data['mail_encryption'] ?? '')));

        // Пока SMTP не задан, письма пишутся в лог. Это осознанный рабочий
        // режим, а не «тихий успех»: отправка не бросает исключение и не рвёт
        // оформление заказа, а SMTP можно включить позже в админке/`.env`.
        $mailer = $mailHost !== '' ? 'smtp' : 'log';

        $fromAddress = trim((string) ($data['mail_from_address'] ?? '')) ?: (string) $data['admin_email'];
        $fromName = trim((string) ($data['mail_from_name'] ?? '')) ?: (string) $data['app_name'];

        $replacements = [
            '{APP_NAME}' => $this->envValue((string) $data['app_name']),
            '{APP_ENV}' => 'production',
            '{APP_KEY}' => $appKey,
            '{APP_DEBUG}' => 'false',
            '{APP_URL}' => $this->envValue((string) $data['app_url']),
            '{NABILET_SEED_SURGUT}' => ! empty($data['seed_surgut']) ? 'true' : 'false',
            '{APP_LOCALE}' => (string) $data['locale'],
            '{APP_FALLBACK_LOCALE}' => (string) $data['locale'] === 'en' ? 'ru' : 'en',
            '{APP_TIMEZONE}' => (string) $data['timezone'],
            '{NABILET_LOCALE}' => (string) $data['locale'],
            '{NABILET_REGION}' => $this->envValue(trim((string) ($data['region'] ?? ''))),
            '{NABILET_CURRENCY}' => (string) $data['currency'],
            '{NABILET_PLATFORM_ADMINS}' => $this->envValue(strtolower(trim((string) $data['admin_email']))),
            '{NABILET_UPDATE_PUBLIC_KEY}' => $this->publicKeyForEnv(trim((string) ($data['updates_public_key'] ?? ''))),
            '{NABILET_UPDATE_FEED_URL}' => $this->envValue(trim((string) ($data['updates_feed_url'] ?? ''))),
            '{LOG_CHANNEL}' => 'stack',
            '{LOG_LEVEL}' => 'debug',
            '{DB_CONNECTION}' => 'mysql',
            '{DB_HOST}' => $this->envValue((string) $data['db_host']),
            '{DB_PORT}' => (string) $data['db_port'],
            '{DB_DATABASE}' => $this->envValue((string) $data['db_database']),
            '{DB_USERNAME}' => $this->envValue((string) $data['db_username']),
            '{DB_PASSWORD}' => $this->envValue((string) $data['db_password']),
            '{DB_CHARSET}' => 'utf8mb4',
            '{DB_COLLATION}' => 'utf8mb4_unicode_ci',
            '{CACHE_STORE}' => 'database',
            '{FILESYSTEM_DISK}' => 'public',
            '{QUEUE_CONNECTION}' => 'database',
            '{SESSION_DRIVER}' => 'database',
            '{MAIL_MAILER}' => $mailer,
            '{MAIL_HOST}' => $this->envValue($mailHost),
            '{MAIL_PORT}' => ((string) ($data['mail_port'] ?? '')) !== '' ? (string) $data['mail_port'] : '587',
            '{MAIL_USERNAME}' => $this->envValue($mailUsername),
            '{MAIL_PASSWORD}' => $this->envValue($mailPassword),
            '{MAIL_ENCRYPTION}' => $this->envValue($mailEncryption),
            '{MAIL_FROM_ADDRESS}' => $this->envValue($fromAddress),
            '{MAIL_FROM_NAME}' => $this->envValue($fromName),
            '{YOOKASSA_SHOP_ID}' => $this->envValue((string) ($data['yookassa_shop_id'] ?? '')),
            '{YOOKASSA_SECRET_KEY}' => $this->envValue((string) ($data['yookassa_api_key'] ?? '')),
        ];

        // strtr() вместо str_replace(): подстановка по ключам за один проход,
        // без риска, что значение одного плейсхолдера случайно совпадёт с именем
        // другого. Раньше APP_NAME подставлялся отдельным str_replace поверх
        // шаблона — лишняя точка отказа.
        return strtr(self::ENV_TEMPLATE, $replacements);
    }

    /**
     * Привести значение к безопасной для `.env` форме.
     *
     * Креды БД, YooKassa и почты раньше писались «как есть»: пароль с пробелом,
     * `#`, кавычкой или переводом строки разрывал файл и ломал установку
     * (аудит установщика §7). Простые значения оставляем без кавычек — так
     * `.env` читается привычно, — остальные оборачиваем в двойные кавычки.
     */
    private function envValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.:\/@\-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '', '\\n'],
            $value
        ) . '"';
    }

    /** Публичный ключ: PEM, \n-as-text или base64 DER — всё приводится к PEM. */
    private function normalizePublicKey(string $raw): string
    {
        $raw = str_replace('\\n', "\n", trim($raw));
        if (str_contains($raw, 'BEGIN PUBLIC KEY')) {
            return $raw;
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return '';
        }

        return str_contains($decoded, 'BEGIN PUBLIC KEY')
            ? $decoded
            : "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($decoded), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Сохранить публичный ключ одной строкой base64 DER (без переводов строк в .env). */
    private function publicKeyForEnv(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $key = openssl_pkey_get_public($this->normalizePublicKey($raw));
        if ($key === false) {
            return '';
        }
        $pem = (string) (openssl_pkey_get_details($key)['key'] ?? '');
        $der = preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?: '';

        return base64_encode(base64_decode($der, true) ?: $der);
    }

    /**
     * Создать пользователя-администратора
     */
    private function createAdminUser(string $email, string $password, string $orgName): void
    {
        // Повтор после частичной установки: админ уже создан — не дублируем,
        // а переиспользуем его и доводим связи до нужного состояния.
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing !== null) {
            $this->bootstrapOrganization((int) $existing->id, $orgName);
            Log::info('Installer: существующий администратор переиспользован', ['id' => $existing->id]);

            return;
        }

        $userId = null;

        // Каноничная модель — единственная, через которую теперь создаётся
        // пользователь (`App\Models\User` осталось легаси-именем-подклассом).
        // Ветка `DB::table()` ниже — не дубль, а запас на случай, когда модель
        // недоступна (сломанный автолоад): установщик обязан доработать до
        // админа, иначе инсталляция заканчивается мёртвым сайтом без входа.
        if (class_exists(\Nabilet\Modules\Core\Users\Models\User::class)) {
            $user = \Nabilet\Modules\Core\Users\Models\User::create([
                'first_name' => 'Administrator',
                'email' => $email,
                'password' => bcrypt($password),
                'email_verified_at' => now(),
                'status' => UserStatus::ACTIVE,
            ]);
            $userId = $user->id;
            Log::info('Installer: Администратор создан', ['id' => $userId, 'email' => $email]);
        } else {
            Log::warning('Installer: Модель User не найдена, создаем админа через DB');
            $userId = DB::table('users')->insertGetId([
                'public_id' => PublicId::generate(),
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'first_name' => 'Administrator',
                'status' => UserStatus::ACTIVE,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Log::info('Installer: Администратор создан через DB', ['id' => $userId, 'email' => $email]);
        }

        if ($userId) {
            $this->bootstrapOrganization((int) $userId, $orgName);
        }
    }

    /**
     * Create the self-hosted install's default organization and attach the admin.
     *
     * The app is multi-tenant (every tenant-scoped row carries organization_id) and
     * ResolveOrganizationContext resolves the active org from the user's membership.
     * Without this the freshly installed admin would have no org context and
     * tenant-scoped queries would throw TenantContextMissingError. Organization
     * and role assignment are mandatory; a retry reuses existing rows instead of
     * creating duplicates.
     */
    private function bootstrapOrganization(int $userId, string $orgName): void
    {
        $slug = Str::slug($orgName) ?: 'organization';
        $existingOrg = DB::table('organizations')->where('name', $orgName)->first()
            ?? DB::table('organizations')->where('slug', $slug)->first();

        if ($existingOrg !== null) {
            $organizationId = (int) $existingOrg->id;
        } else {
            $organizationId = (int) DB::table('organizations')->insertGetId([
                'public_id' => PublicId::generate(),
                'name' => $orgName,
                'slug' => $slug,
                'status' => PublicationStatus::ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');
        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'Full access (created by installer)',
            ]);
        }

        DB::table('user_organization')->updateOrInsert(
            ['user_id' => $userId, 'organization_id' => $organizationId],
            ['role_id' => $roleId, 'created_at' => now()]
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $userId, 'organization_id' => $organizationId, 'role_id' => $roleId],
            ['granted_by' => null, 'created_at' => now()]
        );

        // Обязательное условие успешной установки: у владельца есть роль admin
        // в organisation scope, иначе tenant-scoped API immediately falls apart.
        $hasRole = DB::table('user_roles')
            ->where('user_id', $userId)->where('organization_id', $organizationId)
            ->where('role_id', $roleId)->exists();
        if (! $hasRole) {
            throw new \RuntimeException('Не удалось назначить роль администратора организации.');
        }

        Log::info('Installer: Организация создана и админ привязан', [
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Отдать последние записи журнала инсталлера визарду.
     *
     * ЗАЧЕМ: на шаред-хостинге без SSH владелец не может прочитать
     * storage/logs, а единственное сообщение об ошибке в визарде —
     * «Не удалось завершить установку. Подробности — в журнале».
     * Диагностика вслепую длила релизы ради одной строки причины.
     *
     * БЕЗОПАСНОСТЬ: эндпоинт живёт только до install.lock — после
     * установки его закрывает 403, посторонние не читают логи живого
     * сайта. В отдачу идут только записи инсталлера (фильтр по
     * префиксу «Installer:»), без полных трейсов: первые кадры стека с
     * обрезанным base_path — достаточно для причины, мало для атаки.
     */
    public function recentLog(Request $request): JsonResponse
    {
        if ($this->isInstalled()) {
            return response()->json(['error' => [
                'code' => 'ALREADY_INSTALLED',
                'message' => 'Система уже установлена',
                'details' => [],
            ]], 403);
        }

        if ($denied = $this->guardInstallKey($request)) {
            return $denied;
        }

        // Вчерашний день тоже: установка, начатая в 23:50, падает в 00:05.
        $files = [
            storage_path('logs/laravel-' . now()->subDay()->format('Y-m-d') . '.log'),
            storage_path('logs/laravel-' . now()->format('Y-m-d') . '.log'),
        ];

        $entries = [];
        foreach ($files as $file) {
            if (! File::exists($file)) {
                continue;
            }

            foreach (file($file) as $line) {
                $entry = $this->parseInstallerLogLine($line);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        // Хвост важнее начала: последний провал — актуальный.
        return response()->json([
            'entries' => array_slice($entries, -6),
        ]);
    }

    /**
     * Разобрать одну строку журнала в запись для визарда; не-инсталлерные
     * строки дают null.
     */
    private function parseInstallerLogLine(string $line): ?array
    {
        $line = trim($line);

        if (! str_contains($line, 'Installer:')) {
            return null;
        }

        if (! preg_match('/^\[([^\]]+)\]\s+\S+\.([A-Z]+):\s+(.*)$/s', $line, $m)) {
            return null;
        }

        [, $timestamp, $level, $rest] = $m;

        $message = $rest;
        $context = [];

        // Laravel доклеивает в конец строки второй JSON {"request_id":...} —
        // снимаем его, иначе разбор контекста отхватит его вместо записи
        // инсталлера.
        $rest = preg_replace('/\{"request_id":"[^"]*"\}\s*$/', '', $rest);

        // Контекст — JSON в конце строки (Laravel пишет его после сообщения).
        if (($brace = strrpos($rest, '{"')) !== false) {
            $decoded = json_decode((string) substr($rest, $brace), true);
            if (is_array($decoded)) {
                $context = $decoded;
                $message = rtrim((string) substr($rest, 0, $brace));
            }
        }

        $trace = '';
        if (is_string($context['trace'] ?? null)) {
            $trace = $context['trace'];
        }

        // base_path из кадров стека наружу не отдаём.
        $frames = [];
        foreach (explode("\n", $trace) as $frame) {
            $frame = trim($frame);
            if ($frame === '') {
                continue;
            }
            if (count($frames) >= 4) {
                break;
            }
            $frames[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $frame);
        }

        return [
            'time' => $timestamp,
            'level' => strtolower($level),
            'message' => $message,
            'exception' => is_string($context['exception'] ?? null) ? $context['exception'] : '',
            'trace' => $frames,
        ];
    }

    /**
     * Проверить, установлена ли система
     */
    /**
     * Итоговая диагностика установки.
     *
     * Критические находки — отсутствующий lock, .env, БД, миграции, admin,
     * platform admin — означают «установка не завершена». Остальные пункты
     * (cron, MCP, обновления, SMTP) — предупреждения с конкретным действием.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, critical: list<string>, warnings: list<string>, checks: array<string, bool>}
     */
    private function finalDiagnostics(array $data): array
    {
        $critical = [];
        $warnings = [];
        $checks = [];

        $checks['install_lock'] = $this->isInstalled();
        if (! $checks['install_lock']) {
            $critical[] = 'Файл storage/install.lock не создан — установщик остаётся открытым.';
        }

        $env = File::exists(base_path('.env')) ? (string) File::get(base_path('.env')) : '';
        $checks['env'] = str_contains($env, 'APP_KEY=base64:');
        if (! $checks['env']) {
            $critical[] = 'Файл .env отсутствует или в нём нет APP_KEY.';
        }

        try {
            DB::connection()->select('select 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
            $critical[] = 'База данных недоступна после установки. Проверьте DB_* в .env.';
        }

        try {
            $tables = DB::select("select table_name from information_schema.tables where table_schema = database()");
            $checks['migrations'] = count($tables) > 90;
        } catch (\Throwable) {
            $checks['migrations'] = false;
        }
        if (! $checks['migrations']) {
            $critical[] = 'Миграции не применились полностью (ожидается более 90 таблиц).';
        }

        $adminEmail = strtolower(trim((string) $data['admin_email']));
        try {
            $checks['admin'] = DB::table('users')->where('email', $adminEmail)->exists();
            $checks['organization'] = DB::table('organizations')->count() > 0;
            $checks['admin_role'] = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->join('users', 'users.id', '=', 'user_roles.user_id')
                ->where('users.email', $adminEmail)->where('roles.slug', 'admin')->exists();
        } catch (\Throwable) {
            $checks['admin'] = $checks['organization'] = $checks['admin_role'] = false;
        }
        if (! $checks['admin']) {
            $critical[] = 'Администратор не создан.';
        }
        if (! $checks['organization']) {
            $critical[] = 'Организация не создана — tenant-scoped API будет недоступен.';
        }
        if (! $checks['admin_role']) {
            $critical[] = 'Роль admin не назначена владельцу установки.';
        }

        $platformAdmins = array_map('strtolower', (array) config('nabilet.platform_admins', []));
        $checks['platform_admin'] = in_array($adminEmail, $platformAdmins, true)
            || str_contains($env, 'NABILET_PLATFORM_ADMINS=' . $adminEmail);
        if (! $checks['platform_admin']) {
            $critical[] = 'Владелец не назначен системным администратором (NABILET_PLATFORM_ADMINS).';
        }

        $checks['storage_writable'] = is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'));
        if (! $checks['storage_writable']) {
            $warnings[] = 'Проверьте права на запись: storage/ и bootstrap/cache.';
        }
        if (! str_contains($env, 'MAIL_MAILER=smtp')) {
            $warnings[] = 'SMTP не настроен: письма пока пишутся в лог. Укажите MAIL_* позже.';
        }
        if (trim((string) config('updates.feed_url', '')) === '') {
            $warnings[] = 'Адрес ленты обновлений не задан: доступна ручная загрузка ZIP.';
        }
        if (trim((string) config('updates.public_key', '')) === '') {
            $warnings[] = 'Публичный ключ обновлений не задан: автоматические обновления недоступны.';
        }
        $warnings[] = 'Настройте cron: php artisan schedule:run каждую минуту.';
        $warnings[] = 'Для ИИ-агента выпустите токен с нужными полномочиями в разделе «ИИ-агент».';

        return [
            'ok' => $critical === [],
            'critical' => $critical,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /** @return array<string, string> */
    private function nextSteps(): array
    {
        return [
            'cron' => '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
            'mcp' => 'POST /mcp с заголовком Authorization: Bearer <токен>; токен выпускается в админке → «ИИ-агент».',
            'updates' => 'На целевом сайте задайте NABILET_UPDATE_PUBLIC_KEY (публичный PEM/base64 DER) '
                . 'и NABILET_UPDATE_FEED_URL (HTTPS-адрес manifest.json). Приватный ключ хранится только в CI.',
        ];
    }

    private function isInstalled(): bool
    {
        return File::exists(storage_path('install.lock'));
    }

    private function statePath(): string
    {
        return storage_path('framework/install-state.json');
    }

    /** @return array<string, mixed>|null */
    private function installState(): ?array
    {
        $path = $this->statePath();
        if (! File::exists($path)) {
            return null;
        }
        $state = json_decode((string) File::get($path), true);

        return is_array($state) ? $state : null;
    }

    private function writeInstallState(string $step, ?string $error = null): void
    {
        $state = [
            'step' => $step,
            'updated_at' => now()->toIso8601String(),
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
        ];
        $path = $this->statePath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }
        File::put($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function clearInstallState(): void
    {
        File::delete($this->statePath());
        File::delete(storage_path('framework/install-in-progress.lock'));
    }

    /**
     * Пометить систему как установленную и закрыть за собой все входы.
     *
     * Порядок важен: сначала lock (новые запросы уже увидят «установлено»),
     * потом зачистка файлов, потом сброс кеша маршрутов — иначе запрос,
     * попавший в окно между записью lock и `route:clear`, доехал бы до
     * закешированного маршрута `/install` и увидел бы страницу мастера.
     *
     * @return list<string> удалённые диагностические файлы/каталоги
     */
    private function markAsInstalled(): array
    {
        File::put(storage_path('install.lock'), now()->toIso8601String());
        InstallKey::forget();
        $this->clearInstallState();
        Log::info('Installer: Система помечена как установленная');

        // Временный ключ на окно установки больше не нужен: реальный APP_KEY
        // уже записан в .env, а с этого момента пустой/битый ключ обязан
        // падать громко (fail-loud) — временная подмена условия не ослабляет.
        // Оставленный файл был бы мёртвым грузом в списке «что это за файл».
        File::delete(PreinstallAppKey::persistencePath());

        // info.php / phpinfo.php / legacy-каталог install — не часть архива,
        // но после выкладки живут в docroot и открываются напрямую, минуя
        // Laravel. Удаляем по белому списку имён (SetupArtifactCleaner).
        $removed = SetupArtifactCleaner::make()->purge();
        if ($removed !== []) {
            Log::info('Installer: удалены файлы развёртывания', ['paths' => $removed]);
        }

        // Маршруты установщика регистрируются только без install.lock
        // (routes/web.php). Если маршруты были закешированы до установки,
        // кеш надо сбросить — иначе /install останется доступен до следующей
        // очистки кеша вручную.
        Artisan::call('route:clear');

        return $removed;
    }
}
