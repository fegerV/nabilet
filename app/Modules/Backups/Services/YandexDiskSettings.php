<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Services;

use Illuminate\Support\Facades\Config;
use Nabilet\Modules\Core\Settings\SettingsStore;

/**
 * Настройки подключения к Яндекс.Диску (scope `yandex_disk`).
 *
 * Токен OAuth — секрет: пишется зашифрованным через SettingsStore (Crypt,
 * ключ из APP_KEY) и НЕ отдаётся наружу ни в одном ответе админки. Наружу
 * только признак «подключён» и служебные пути.
 *
 * Значения из БД перекрывают .env через applyRuntimeConfig(): старые
 * установки, где токен прописан в YANDEX_DISK_OAUTH_TOKEN, продолжают
 * работать без миграции, а новое подключение из админки не требует SSH.
 */
final class YandexDiskSettings
{
    public const SCOPE = 'yandex_disk';

    public const KEY_CLIENT_ID = 'client_id';
    public const KEY_TOKEN = 'oauth_token';
    public const KEY_PATH = 'backups_path';
    public const KEY_CONNECTED_AT = 'connected_at';
    public const KEY_LAST_RUN_STATUS = 'last_run_status';
    public const KEY_LAST_RUN_MODE = 'last_run_mode';
    public const KEY_LAST_RUN_AT = 'last_run_at';
    public const KEY_LAST_RUN_MESSAGE = 'last_run_message';

    /** Секреты: в БД зашифрованы, наружу не отдаются. */
    public const SECRET_KEYS = [self::KEY_TOKEN];

    private SettingsStore $store;

    public function __construct()
    {
        $this->store = new SettingsStore();
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->store->values(self::SCOPE);
    }

    public function clientId(): string
    {
        return (string) ($this->values()[self::KEY_CLIENT_ID] ?? '');
    }

    public function backupsPath(): string
    {
        $fromDb = (string) ($this->values()[self::KEY_PATH] ?? '');
        if ($fromDb !== '') {
            return $fromDb;
        }

        return (string) (config('services.yandex_disk.backups_path', '/backups') ?: '/backups');
    }

    public function token(): string
    {
        $values = $this->values();
        $token = (string) ($values[self::KEY_TOKEN] ?? '');

        if ($token !== '') {
            $decrypted = SettingsStore::decrypt($token);

            return $decrypted ?? '';
        }

        // Фолбэк на .env для совместимости со старыми установками.
        return (string) (config('services.yandex_disk.oauth_token') ?? '');
    }

    public function isConnected(): bool
    {
        return $this->token() !== '';
    }

    public function connectedFromDb(): bool
    {
        $raw = (string) ($this->values()[self::KEY_TOKEN] ?? '');

        return $raw !== '';
    }

    /** Адрес, который надо зарегистрировать в приложении Яндекс OAuth. */
    public function callbackUrl(): string
    {
        // Берём реальный хост запроса: APP_URL в .env часто остаётся
        // «https://tickets.example.com» после клона, а Яндекс требует точного
        // совпадения Callback URI. В консоли фолбэк на APP_URL.
        $host = app()->runningInConsole() ? '' : request()->getSchemeAndHttpHost();
        if ($host === '') {
            $host = rtrim((string) config('app.url', ''), '/');
        }

        return rtrim($host, '/') . '/admin/backups/oauth';
    }

    public function saveClientId(string $clientId): void
    {
        $this->store->write(self::SCOPE, self::KEY_CLIENT_ID, trim($clientId));
        $this->applyRuntimeConfig();
    }

    public function saveToken(string $token): void
    {
        $this->store->write(self::SCOPE, self::KEY_TOKEN, trim($token), encrypted: true);
        $this->store->write(self::SCOPE, self::KEY_CONNECTED_AT, now()->toIso8601String());
        $this->applyRuntimeConfig();
    }

    public function saveBackupsPath(string $path): void
    {
        $path = trim($path);
        if ($path !== '') {
            $this->store->write(self::SCOPE, self::KEY_PATH, $path);
        } else {
            $this->store->forget(self::SCOPE, self::KEY_PATH);
        }
        $this->applyRuntimeConfig();
    }

    public function forgetToken(): void
    {
        $this->store->forget(self::SCOPE, self::KEY_TOKEN);
        $this->store->forget(self::SCOPE, self::KEY_CONNECTED_AT);
        // Сначала сбросить runtime-config: иначе token() в applyRuntimeConfig()
        // прочитал бы ещё не очищенное значение из этого же запроса и «отлючение»
        // осталось бы только на бумаге до следующего запроса.
        Config::set('services.yandex_disk.oauth_token', null);
        $this->applyRuntimeConfig();
    }

    /** @return array<string, mixed> */
    public function forAdmin(): array
    {
        $values = $this->values();

        return [
            'client_id' => $this->clientId(),
            'callback_url' => $this->callbackUrl(),
            'backups_path' => $this->backupsPath(),
            'connected' => $this->isConnected(),
            'connected_from_db' => $this->connectedFromDb(),
            'connected_at' => $values[self::KEY_CONNECTED_AT] ?? null,
            'last_run' => [
                'status' => $values[self::KEY_LAST_RUN_STATUS] ?? null,
                'mode' => $values[self::KEY_LAST_RUN_MODE] ?? null,
                'at' => $values[self::KEY_LAST_RUN_AT] ?? null,
                'message' => $values[self::KEY_LAST_RUN_MESSAGE] ?? null,
            ],
        ];
    }

    public function recordRun(string $mode, string $status, string $message): void
    {
        $this->store->write(self::SCOPE, self::KEY_LAST_RUN_MODE, $mode);
        $this->store->write(self::SCOPE, self::KEY_LAST_RUN_STATUS, $status);
        $this->store->write(self::SCOPE, self::KEY_LAST_RUN_AT, now()->toIso8601String());
        $this->store->write(self::SCOPE, self::KEY_LAST_RUN_MESSAGE, mb_substr($message, 0, 500));
    }

    /**
     * Положить действующие значения в runtime-конфиг: сервис бэкапов и
     * команда читают config(), оставаясь чистыми и тестируемыми без БД.
     */
    public function applyRuntimeConfig(): void
    {
        $token = $this->token();
        if ($token !== '') {
            Config::set('services.yandex_disk.oauth_token', $token);
        }
        Config::set('services.yandex_disk.backups_path', $this->backupsPath());
    }
}
