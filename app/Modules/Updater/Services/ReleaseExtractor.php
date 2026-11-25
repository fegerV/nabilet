<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Nabilet\Core\Errors\AppError;

/**
 * Безопасная распаковка релиза в staging-каталог.
 *
 * Архив приходит извне, поэтому он рассматривается как враждебный ввод: путь
 * проверяется по сегментам до записи, симлинки и точки вне whitelist отвергаются,
 * а суммарный размер ограничивает zip bomb. `extractTo()` здесь НЕ используется —
 * он не даёт такого контроля.
 */
final class ReleaseExtractor
{
    /** Каталоги, которые релиз вправе перезаписать. */
    private const DIRS = [
        'app', 'bootstrap', 'config', 'database', 'lang', 'resources',
        'routes', 'dist', 'vendor', 'public', 'public_html',
    ];

    /** Корневые файлы релиза. */
    private const ROOT_FILES = [
        'artisan', 'composer.json', 'composer.lock', '.env.example', 'CHANGELOG.md',
    ];

    /** В docroot обновляем только код входа и собранные ассеты. */
    private const PUBLIC_HTML_FILES = [
        'index.php', '.htaccess', '.user.ini', 'robots.txt', 'favicon.svg', 'installer-tailwind.js',
    ];
    private const PUBLIC_HTML_DIRS = ['assets'];

    /** Файлы, без которых релиз не является рабочим. */
    private const REQUIRED = [
        'bootstrap/app.php',
        'config/app.php',
        'public/index.php',
        'public_html/index.php',
        'vendor/autoload.php',
        'artisan',
    ];

    public function __construct(private readonly UpdateStorage $storage)
    {
    }

    /**
     * @return array{entries: int, bytes: int}
     */
    public function extract(string $archive, int $updateId): array
    {
        $staging = $this->storage->staging($updateId);
        if (is_dir($staging)) {
            $this->storage->deleteTree($staging);
        }
        if (! mkdir($staging, 0775, true) && ! is_dir($staging)) {
            throw new AppError('Не удалось создать каталог распаковки.', 'UPDATE_EXTRACT_FAILED', 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new AppError('Архив обновления не является корректным ZIP.', 'UPDATE_ARCHIVE_INVALID', 422);
        }

        $base = realpath($staging);
        if ($base === false) {
            $zip->close();

            throw new AppError('Не удалось подготовить каталог распаковки.', 'UPDATE_EXTRACT_FAILED', 500);
        }

        $entries = 0;
        $bytes = 0;
        $maxEntries = (int) config('updates.max_entries', 20000);
        $maxBytes = (int) config('updates.max_unpacked_bytes', 512 * 1024 * 1024);

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $rawName = is_array($stat) ? (string) $stat['name'] : '';
                $name = $this->safeName($rawName);

                if ($name === '') {
                    // Каталоги и намеренно пропущенные служебные записи
                    // (public_html/images, storage, bootstrap/cache) не файлы.
                    continue;
                }

                if (++$entries > $maxEntries) {
                    throw new AppError('В архиве слишком много файлов.', 'UPDATE_ARCHIVE_INVALID', 422);
                }

                if ($this->isSymlink($stat)) {
                    throw new AppError('Архив обновления содержит символические ссылки.', 'UPDATE_ARCHIVE_UNSAFE', 422);
                }

                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new AppError('Не удалось прочитать файл из архива обновления.', 'UPDATE_ARCHIVE_INVALID', 422);
                }

                $bytes += strlen($contents);
                if ($bytes > $maxBytes) {
                    throw new AppError('Распакованный архив слишком большой.', 'UPDATE_ARCHIVE_INVALID', 422);
                }

                $target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
                $dir = dirname($target);
                if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                    throw new AppError('Не удалось создать каталог распаковки.', 'UPDATE_EXTRACT_FAILED', 500);
                }

                $resolvedDir = realpath($dir);
                if ($resolvedDir === false || ! str_starts_with($resolvedDir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) {
                    throw new AppError('Архив обновления содержит небезопасный путь.', 'UPDATE_ARCHIVE_UNSAFE', 422);
                }

                if (file_put_contents($target, $contents, LOCK_EX) === false) {
                    throw new AppError('Не удалось записать файл обновления.', 'UPDATE_EXTRACT_FAILED', 500);
                }
            }
        } finally {
            $zip->close();
        }

        foreach (self::REQUIRED as $file) {
            if (! is_file($staging . '/' . $file)) {
                throw new AppError(
                    sprintf('В архиве отсутствует обязательный файл %s.', $file),
                    'UPDATE_ARCHIVE_INCOMPLETE',
                    422,
                );
            }
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    /**
     * Возвращает относительный путь с прямыми слешами или '' для записей,
     * которые нужно пропустить (каталог, storage, public_html/images и т.п.).
     */
    private function safeName(string $raw): string
    {
        $name = str_replace('\\', '/', $raw);
        if (str_ends_with($name, '/')) {
            return '';
        }
        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }

        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            throw new AppError('Архив обновления содержит небезопасный путь.', 'UPDATE_ARCHIVE_UNSAFE', 422);
        }

        $parts = explode('/', $name);
        $top = $parts[0];

        if (count($parts) === 1 && in_array($name, self::ROOT_FILES, true)) {
            return $name;
        }

        if (! in_array($top, self::DIRS, true)) {
            throw new AppError('Архив содержит файл вне разрешённых каталогов.', 'UPDATE_ARCHIVE_UNSAFE', 422);
        }

        // `storage` не входит в DIRS и отвергается выше. Кэш не должен
        // приезжать из архива ни при каких обстоятельствах — это данные установки.
        if ($top === 'bootstrap' && ($parts[1] ?? '') === 'cache') {
            return '';
        }

        if ($top === 'public_html') {
            if (count($parts) === 2 && in_array($parts[1], self::PUBLIC_HTML_FILES, true)) {
                return $name;
            }
            if (count($parts) >= 2 && in_array($parts[1], self::PUBLIC_HTML_DIRS, true)) {
                return $name;
            }

            return '';
        }

        // Скрытые файлы внутри каталогов релиза не нужны (и не должны
        // перезаписывать пользовательские .htaccess/.user.ini).
        foreach ($parts as $part) {
            if (str_starts_with($part, '.')) {
                throw new AppError('Архив обновления содержит скрытый файл.', 'UPDATE_ARCHIVE_UNSAFE', 422);
            }
        }

        return $name;
    }

    /** @param array<string, mixed>|false $stat */
    private function isSymlink(array|false $stat): bool
    {
        if (! is_array($stat) || ! isset($stat['external_attributes'])) {
            return false;
        }

        // Unix-режим лежит в старших 16 битах внешних атрибутов. 0120000 —
        // символическая ссылка; архивы, собранные на Windows, дают 0.
        $mode = ((int) $stat['external_attributes'] >> 16) & 0170000;

        return $mode === 0120000;
    }
}
