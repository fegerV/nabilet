<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * InstallerController — пошаговый установщик системы для шаред-хостинга.
 *
 * Предназначен для одноразового использования при первом запуске:
 * 1. Проверка требований сервера (PHP, расширения, права доступа)
 * 2. Настройка подключения к БД и запись .env
 * 3. Запуск миграций и сидеров
 * 4. Создание администратора Filament
 * 5. Создание symbolic link для storage
 * 6. Блокировка повторного запуска через storage/install.lock
 *
 * После успешной установки файл storage/install.lock блокирует доступ к /install
 */
class InstallerController extends Controller
{
    private const ENV_TEMPLATE = <<<'ENV'
APP_NAME="Nabilet Ticketing"
APP_ENV={APP_ENV}
APP_KEY={APP_KEY}
APP_DEBUG={APP_DEBUG}
APP_URL={APP_URL}
APP_LOCALE={APP_LOCALE}
APP_FALLBACK_LOCALE={APP_FALLBACK_LOCALE}
APP_TIMEZONE={APP_TIMEZONE}

LOG_CHANNEL={LOG_CHANNEL}
LOG_LEVEL={LOG_LEVEL}

DB_CONNECTION={DB_CONNECTION}
DB_HOST={DB_HOST}
DB_PORT={DB_PORT}
DB_DATABASE={DB_DATABASE}
DB_USERNAME={DB_USERNAME}
DB_PASSWORD={DB_PASSWORD}

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK={FILESYSTEM_DISK}
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

YOOKASSA_SHOP_ID={YOOKASSA_SHOP_ID}
YOOKASSA_API_KEY={YOOKASSA_API_KEY}
ENV;

    /**
     * Показать форму установщика или вернуть JSON с требованиями
     */
    public function show(Request $request)
    {
        // Если уже установлено — блокируем доступ
        if ($this->isInstalled()) {
            return $request->expectsJson()
                ? response()->json(['error' => 'Система уже установлена'], 403)
                : redirect('/')->with('error', 'Система уже установлена');
        }

        $requirements = $this->checkRequirements();
        $passed = $requirements['passed'];

        if ($request->expectsJson()) {
            return response()->json([
                'step' => 'requirements',
                'passed' => $passed,
                'requirements' => $requirements['details'],
            ]);
        }

        return view('installer::install', [
            'passed' => $passed,
            'requirements' => $requirements['details'],
        ]);
    }

    /**
     * Обработать форму установки
     */
    public function install(Request $request): JsonResponse
    {
        // Если уже установлено — блокируем
        if ($this->isInstalled()) {
            return response()->json(['error' => 'Система уже установлена'], 403);
        }

        try {
            $validated = $request->validate([
                'app_name' => ['required', 'string', 'max:255'],
                'app_url' => ['required', 'url', 'max:255'],
                'db_host' => ['required', 'string', 'max:255'],
                'db_port' => ['required', 'integer', 'between:1,65535'],
                'db_database' => ['required', 'string', 'max:255'],
                'db_username' => ['required', 'string', 'max:255'],
                'db_password' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'email', 'max:255'],
                'admin_password' => ['required', 'string', 'min:8', 'max:255'],
                'yookassa_shop_id' => ['nullable', 'string', 'max:255'],
                'yookassa_api_key' => ['nullable', 'string', 'max:255'],
            ]);

            // Шаг 1: Проверка подключения к БД
            $this->testDatabaseConnection(
                $validated['db_host'],
                $validated['db_port'],
                $validated['db_database'],
                $validated['db_username'],
                $validated['db_password']
            );

            // Шаг 2: Запись .env файла
            $this->writeEnvFile($validated);

            // Шаг 3: Очистка кэша конфигурации
            Artisan::call('config:clear');
            Artisan::call('cache:clear');

            // Шаг 4: Запуск миграций
            $migrateExitCode = Artisan::call('migrate', ['--force' => true]);
            if ($migrateExitCode !== 0) {
                throw new \RuntimeException('Не удалось выполнить миграции базы данных');
            }

            // Шаг 5: Создание администратора
            $this->createAdminUser($validated['admin_email'], $validated['admin_password']);

            // Шаг 6: Создание symbolic link для storage
            try {
                Artisan::call('storage:link');
            } catch (\Throwable $e) {
                Log::warning('Installer: Не удалось создать storage:link', ['error' => $e->getMessage()]);
                // Не блокируем установку, если symlink не работает
            }

            // Шаг 7: Запуск сидеров (если есть)
            try {
                Artisan::call('db:seed', ['--force' => true]);
            } catch (\Throwable $e) {
                Log::warning('Installer: Сидеры не выполнены', ['error' => $e->getMessage()]);
            }

            // Шаг 8: Генерация APP_KEY если не был указан
            if (empty(config('app.key'))) {
                Artisan::call('key:generate', ['--force' => true]);
            }

            // Шаг 9: Блокировка повторной установки
            $this->markAsInstalled();

            Log::info('Installer: Система успешно установлена', [
                'app_name' => $validated['app_name'],
                'app_url' => $validated['app_url'],
                'db_host' => $validated['db_host'],
                'admin_email' => $validated['admin_email'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Система успешно установлена',
                'redirect' => '/admin',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка валидации',
                'messages' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Installer: Критическая ошибка установки', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ошибка установки: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Проверить требования сервера
     */
    private function checkRequirements(): array
    {
        $details = [];
        $allPassed = true;

        // Проверка версии PHP
        $phpVersion = PHP_VERSION;
        $phpRequired = '8.1';
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

        // Проверка прав на запись
        $writablePaths = [
            storage_path(),
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
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
    private function testDatabaseConnection(string $host, int $port, string $database, string $username, string $password): void
    {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ];

            new \PDO($dsn, $username, $password, $options);

            Log::info('Installer: Подключение к БД успешно', ['host' => $host, 'database' => $database]);

        } catch (\PDOException $e) {
            Log::error('Installer: Не удалось подключиться к БД', [
                'host' => $host,
                'database' => $database,
                'error' => $e->getMessage(),
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
    private function writeEnvFile(array $data): void
    {
        $envContent = self::ENV_TEMPLATE;
        $appKey = 'base64:' . base64_encode(random_bytes(32));

        $replacements = [
            '{APP_ENV}' => 'production',
            '{APP_KEY}' => $appKey,
            '{APP_DEBUG}' => 'false',
            '{APP_URL}' => $data['app_url'],
            '{APP_LOCALE}' => 'ru',
            '{APP_FALLBACK_LOCALE}' => 'en',
            '{APP_TIMEZONE}' => 'Europe/Moscow',
            '{LOG_CHANNEL}' => 'stack',
            '{LOG_LEVEL}' => 'debug',
            '{DB_CONNECTION}' => 'mysql',
            '{DB_HOST}' => $data['db_host'],
            '{DB_PORT}' => (string) $data['db_port'],
            '{DB_DATABASE}' => $data['db_database'],
            '{DB_USERNAME}' => $data['db_username'],
            '{DB_PASSWORD}' => $data['db_password'],
            '{FILESYSTEM_DISK}' => 'public',
            '{YOOKASSA_SHOP_ID}' => $data['yookassa_shop_id'] ?? '',
            '{YOOKASSA_API_KEY}' => $data['yookassa_api_key'] ?? '',
        ];

        $envContent = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $envContent
        );

        // Добавляем имя приложения
        $envContent = str_replace('APP_NAME="Nabilet Ticketing"', 'APP_NAME="' . $data['app_name'] . '"', $envContent);

        $envPath = base_path('.env');
        
        if (!File::put($envPath, $envContent)) {
            throw new \RuntimeException('Не удалось записать файл .env. Проверьте права на запись в корень проекта.');
        }

        Log::info('Installer: Файл .env успешно создан');
    }

    /**
     * Создать пользователя-администратора
     */
    private function createAdminUser(string $email, string $password): void
    {
        // Проверяем, существует ли модель User
        if (!class_exists(\App\Models\User::class)) {
            Log::warning('Installer: Модель User не найдена, создаем базового админа через DB');
            
            // Создаем через Query Builder если модели нет
            $userId = DB::table('users')->insertGetId([
                'name' => 'Administrator',
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($userId) {
                Log::info('Installer: Администратор создан через DB', ['id' => $userId, 'email' => $email]);
            }
            return;
        }

        // Используем модель если она существует
        $user = \App\Models\User::create([
            'name' => 'Administrator',
            'email' => $email,
            'password' => bcrypt($password),
            'email_verified_at' => now(),
        ]);

        Log::info('Installer: Администратор создан', ['id' => $user->id, 'email' => $user->email]);
    }

    /**
     * Проверить, установлена ли система
     */
    private function isInstalled(): bool
    {
        return File::exists(storage_path('install.lock'));
    }

    /**
     * Пометить систему как установленную
     */
    private function markAsInstalled(): void
    {
        File::put(storage_path('install.lock'), now()->toIso8601String());
        Log::info('Installer: Система помечена как установленная');
    }
}
