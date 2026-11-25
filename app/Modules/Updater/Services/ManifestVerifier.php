<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Nabilet\Core\Errors\AppError;
use Nabilet\Modules\Updater\Domain\ReleaseManifest;

/**
 * Проверка манифеста до того, как будет скачан или распакован сам архив.
 *
 * Подпись — через `ext-openssl`, который уже обязателен в composer.json. На
 * `ext-sodium` опираться нельзя: его в зависимостях проекта нет, а на шаред-
 * хостинге расширение может быть отключено. Публичный ключ зашит в конфиге —
 * компрометация сервера раздачи не позволяет подсунуть подменённый релиз.
 */
final class ManifestVerifier
{
    public function verify(ReleaseManifest $manifest): void
    {
        $this->verifySignature($manifest);
        $this->verifyPlatform($manifest);

        if ($manifest->channel !== (string) config('updates.channel', 'stable')) {
            throw new AppError(
                'Релиз предназначен для другого канала обновлений.',
                'UPDATE_CHANNEL_MISMATCH',
                422,
            );
        }

        if ($manifest->sizeBytes > (int) config('updates.max_bytes', 64 * 1024 * 1024)) {
            throw new AppError('Архив обновления слишком большой.', 'UPDATE_TOO_LARGE', 422);
        }
    }

    /** Подпись нужна только для ленты; ручная загрузка проверяется по sha256. */
    public function verifySignature(ReleaseManifest $manifest): void
    {
        if ($manifest->signature === '') {
            throw new AppError(
                'Манифест релиза не подписан. Используйте ручную загрузку архива.',
                'UPDATE_SIGNATURE_MISSING',
                422,
            );
        }

        $key = $this->publicKey();
        $signature = base64_decode($manifest->signature, true);
        if ($signature === false || $signature === '') {
            throw new AppError('Некорректная подпись манифеста.', 'UPDATE_SIGNATURE_INVALID', 422);
        }

        $result = openssl_verify($manifest->canonical(), $signature, $key, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new AppError('Подпись манифеста не прошла проверку.', 'UPDATE_SIGNATURE_INVALID', 422);
        }
    }

    public function verifyPlatform(ReleaseManifest $manifest): void
    {
        if (version_compare(PHP_VERSION, $manifest->phpMin, '<')
            || version_compare(PHP_VERSION, $manifest->phpMax, '>=')) {
            throw new AppError(
                sprintf('Релиз требует PHP %s … %s, текущая версия %s.', $manifest->phpMin, $manifest->phpMax, PHP_VERSION),
                'UPDATE_PHP_INCOMPATIBLE',
                422,
            );
        }

        foreach ($manifest->extensions as $extension) {
            if (! extension_loaded($extension)) {
                throw new AppError(
                    sprintf('На сервере отсутствует расширение PHP %s.', $extension),
                    'UPDATE_EXTENSION_MISSING',
                    422,
                );
            }
        }
    }

    /** @return \OpenSSLAsymmetricKey */
    private function publicKey(): \OpenSSLAsymmetricKey
    {
        $raw = trim((string) config('updates.public_key', ''));
        if ($raw === '') {
            throw new AppError(
                'Ключ проверки обновлений не настроен. Доступна только ручная загрузка архива.',
                'UPDATE_KEY_NOT_CONFIGURED',
                503,
            );
        }

        // .env не любит переводы строк: разрешаем и "\n" как литерал, и base64
        // DER, и уже готовый PEM — любая из трёх форм приводит к одному PEM.
        $raw = str_replace('\\n', "\n", $raw);
        if (str_contains($raw, 'BEGIN PUBLIC KEY')) {
            $pem = $raw;
        } else {
            $decoded = base64_decode($raw, true);
            if ($decoded === false || $decoded === '') {
                throw new AppError('Некорректный ключ проверки обновлений.', 'UPDATE_KEY_INVALID', 503);
            }
            $pem = str_contains($decoded, 'BEGIN PUBLIC KEY')
                ? $decoded
                : "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($decoded), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new AppError('Некорректный ключ проверки обновлений.', 'UPDATE_KEY_INVALID', 503);
        }

        return $key;
    }
}
