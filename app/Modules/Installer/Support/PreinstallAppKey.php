<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Support;

use Illuminate\Support\Facades\Log;

/**
 * Гарантия рабочего APP_KEY на окно выполнения визарда.
 *
 * ЯЙЦО И КУРИЦА (найдено 2026-11-04 прогоном визарда на свежей копии кода,
 * DocumentRoot = public_html/ шаред-хостинга): до записи `.env` ключа нет,
 * а роуты инсталлера живут в группе `web`, где `EncryptCookies` резолвит
 * encrypter ещё на этапе dispatch (и terminable middleware — на terminate).
 * Итог: `GET /install` отвечал 500 `MissingAppKeyException` — добраться до
 * формы, которая этот ключ И генерирует, было невозможно.
 *
 * РЕШЕНИЕ: пока `.env` не существует (визард не дошёл до записи файла), на
 * буте гарантированно ставим ключ. Он генерируется один раз и ПЕРСИСТИТСЯ
 * в `storage/`, а не живёт только в памяти процесса: `php -S` на
 * шаред-хостинге и несколько воркеров Apache/FPM дают новое приложение на
 * КАЖДЫЙ запрос, и одноразовый in-memory ключ разорвал бы согласованность
 * cookie/сессий между шагами визарда (загрузка формы, проверка БД, POST
 * установки).
 *
 * ГРАНИЦА ПОСЛЕ УСТАНОВКИ: гейт — ОТСУТСТВИЕ `.env`, а не `install.lock`.
 * Установка пишет файл первым шагом, «упавшая половиной» установка тоже
 * оставляет реальный `.env` — с него и начинается правдивый конфиг (пустой
 * ключ там продолжает падать `MissingAppKeyException`, fail-loud). Гейт по
 * install.lock здесь непозволителен: в dev-репозитории и в тестах его нет
 * ВЕЧНО, подменa работала бы постоянно (2026-11-04: именно поэтому разом
 * упали ~50 phpunit-тестов, рассчитывающих на конверт `database`). Строгий
 * enforce после установки отвечает `ProductionSafetyProvider`.
 */
final class PreinstallAppKey
{
    /**
     * Применить гарантию ключа (вызывается на буте).
     */
    public static function apply(?string $envPath = null): void
    {
        $envPath ??= base_path('.env');

        // Гейт — отсутствие `.env`: до первого шага мастера (запись файла)
        // реальных настроек не существует. По install.lock здесь смотреть
        // НЕЛЬЗЯ: в dev-репозитории и в тестах его нет вечно, и подмена
        // работала бы постоянно.
        if (is_file($envPath)) {
            return;
        }

        config(['app.key' => self::ensure(config('app.key'), self::persistencePath())]);
    }

    /**
     * Вернуть ключ для окна установки, гарантируя его работоспособность.
     *
     * Порядок приоритета:
     *   1. Уже настроенный валидный ключ (например, оператор скопировал
     *      `.env.example` с ключом) — используется как есть, файл НЕ пишется;
     *   2. Сохранённый временный ключ из предыдущего запроса — переживает
     *      перезапуски воркеров;
     *   3. Свежесгенерированный ключ — и запись на диск, чтобы следующий
     *      запрос его увидел.
     *
     * @param  string|null  $configuredKey    Значение `config('app.key')`.
     * @param  string       $persistencePath  Файл для перекрёстного хранения.
     */
    public static function ensure(?string $configuredKey, string $persistencePath): string
    {
        if ($configuredKey !== null && self::looksLikeKey($configuredKey)) {
            return $configuredKey;
        }

        $stored = is_file($persistencePath)
            ? trim((string) file_get_contents($persistencePath))
            : '';

        if ($stored !== '' && self::looksLikeKey($stored)) {
            return $stored;
        }

        $generated = 'base64:' . base64_encode(random_bytes(32));

        $directory = dirname($persistencePath);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            Log::warning('Installer: cannot create the directory for the pre-install APP_KEY', [
                'path' => $persistencePath,
            ]);

            // В памяти ключ всё равно ставится: хотя бы первый запрос формы
            // отработает, а провал writability уже пойман списком требований.
            return $generated;
        }

        if (@file_put_contents($persistencePath, $generated) === false) {
            Log::warning('Installer: cannot persist the pre-install APP_KEY', [
                'path' => $persistencePath,
            ]);
        }

        return $generated;
    }

    /**
     * Путь до файла временного ключа (единое место записи и подчистки).
     */
    public static function persistencePath(): string
    {
        return storage_path('app/installer-ephemeral-app-key');
    }

    /**
     * Похожа ли строка на валидный ключ формата `base64:<32 байта>`.
     */
    private static function looksLikeKey(string $value): bool
    {
        if (! str_starts_with($value, 'base64:')) {
            return false;
        }

        $decoded = base64_decode(substr($value, 7), true);

        return $decoded !== false && strlen($decoded) === 32;
    }
}
