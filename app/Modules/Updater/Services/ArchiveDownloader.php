<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Illuminate\Support\Facades\Http;
use Nabilet\Core\Errors\AppError;

/**
 * Скачивание и приём архива обновления.
 *
 * URL всегда выводится из настроенного адреса ленты — манифест не может
 * указать произвольный адрес (иначе это готовый SSRF из админки). Хэш проверяется
 * ПОСЛЕ записи: проверка «по обещанию» до скачивания ничего не доказывает.
 */
final class ArchiveDownloader
{
    public function artifactUrl(string $artifact): string
    {
        $feed = (string) config('updates.feed_url', '');
        $parts = parse_url($feed);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            throw new AppError('Лента обновлений не настроена.', 'UPDATE_FEED_NOT_CONFIGURED', 503);
        }

        $path = (string) ($parts['path'] ?? '');
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '' : substr($path, 0, $slash);

        return 'https://' . $parts['host']
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . $dir . '/' . basename($artifact);
    }

    public function download(string $url, string $target, string $expectedSha): int
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new AppError('Архив обновления доступен только по HTTPS.', 'UPDATE_INSECURE_SOURCE', 422);
        }

        try {
            $response = Http::timeout((int) config('updates.timeout_seconds', 15))->get($url);
        } catch (\Throwable $e) {
            report($e);

            throw new AppError('Не удалось скачать архив обновления.', 'UPDATE_DOWNLOAD_FAILED', 502);
        }

        if (! $response->successful()) {
            throw new AppError('Сервер обновлений не отдал архив.', 'UPDATE_DOWNLOAD_FAILED', 502);
        }

        $body = $response->body();
        $size = strlen($body);
        if ($size > (int) config('updates.max_bytes', 64 * 1024 * 1024)) {
            throw new AppError('Архив обновления слишком большой.', 'UPDATE_TOO_LARGE', 422);
        }

        $this->write($target, $body);
        $this->verifyHash($target, $expectedSha);

        return $size;
    }

    /**
     * Ручная загрузка: файл уже лежит в storage/app/updates/uploads.
     * Копируем в рабочий каталог обновления, чтобы исходник можно было удалить.
     */
    public function import(string $source, string $target, string $expectedSha): int
    {
        if (! is_file($source)) {
            throw new AppError('Загруженный архив не найден.', 'UPDATE_ARCHIVE_MISSING', 422);
        }

        $size = (int) filesize($source);
        if ($size > (int) config('updates.max_bytes', 64 * 1024 * 1024)) {
            throw new AppError('Архив обновления слишком большой.', 'UPDATE_TOO_LARGE', 422);
        }

        $this->verifyHash($source, $expectedSha);

        if (! copy($source, $target)) {
            throw new AppError('Не удалось сохранить архив обновления.', 'UPDATE_ARCHIVE_SAVE_FAILED', 500);
        }

        return $size;
    }

    public function verifyHash(string $path, string $expectedSha): void
    {
        $expected = strtolower(trim($expectedSha));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new AppError('Некорректная контрольная сумма архива.', 'UPDATE_CHECKSUM_INVALID', 422);
        }

        $actual = hash_file('sha256', $path);
        if ($actual === false || ! hash_equals($expected, $actual)) {
            throw new AppError('Контрольная сумма архива не совпала.', 'UPDATE_CHECKSUM_MISMATCH', 422);
        }
    }

    private function write(string $target, string $contents): void
    {
        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new AppError('Не удалось создать каталог архива обновления.', 'UPDATE_ARCHIVE_SAVE_FAILED', 500);
        }

        $tmp = $target . '.part';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false || ! rename($tmp, $target)) {
            throw new AppError('Не удалось сохранить архив обновления.', 'UPDATE_ARCHIVE_SAVE_FAILED', 500);
        }
    }
}
