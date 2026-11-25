<?php

declare(strict_types=1);

/**
 * Аварийный откат обновления без Laravel, vendor и базы данных.
 *
 * updater копирует этот файл в `storage/framework/updater/recover.php` при
 * старте обновления — туда, куда замена кода не дотягивается. Если новый код не
 * стартует и `php artisan nabilet:update:tick` недоступен, cron дёргает именно
 * этот скрипт:
 *
 *   * * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1 \
 *       || php storage/framework/updater/recover.php <update_id>
 *
 * Скрипт не знает ничего о фреймворке — только rename/copy/unlink и список
 * каталогов, совпадающий со списком в UpdateApplier. Изменения идемпотентны:
 * повторный вызов после частичного отката завершится тем же результатом.
 */
$id = $argv[1] ?? '';
if (! is_string($id) || ctype_digit($id) === false || (int) $id < 1) {
    fwrite(STDERR, "Использование: php recover.php <numeric_update_id>\n");
    exit(1);
}

function find_root(string $dir): ?string
{
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/artisan')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }

    return null;
}

function rmrf(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $target = $item->getPathname();
        if ($item->isDir() && ! $item->isLink()) {
            @rmdir($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
}

function ensure_dir(string $path): void
{
    if (! is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

function restore_file(string $source, string $target): void
{
    ensure_dir(dirname($target));
    if (is_file($source)) {
        if (! @copy($source, $target)) {
            throw new RuntimeException('Не удалось восстановить ' . $target);
        }
    } elseif (is_file($target)) {
        @unlink($target);
    }
}

$root = find_root(__DIR__);
if ($root === null) {
    fwrite(STDERR, "Не найден корень приложения (artisan).\n");
    exit(1);
}

$backup = $root . '/storage/app/updates/' . (int) $id . '/backup';
if (! is_dir($backup)) {
    fwrite(STDERR, "Нет каталога отката: {$backup}\n");
    exit(1);
}

$dirs = [
    'app', 'bootstrap', 'config', 'database', 'lang',
    'resources', 'routes', 'dist', 'vendor', 'public',
];
$rootFiles = ['artisan', 'composer.json', 'composer.lock', '.env.example', 'CHANGELOG.md'];
$publicFiles = ['index.php', 'robots.txt', 'favicon.svg', 'installer-tailwind.js'];

try {
    foreach (array_reverse($dirs) as $dir) {
        $source = $backup . '/' . $dir;
        $target = $root . '/' . $dir;
        if (! is_dir($source)) {
            continue;
        }
        rmrf($target);
        if (! @rename($source, $target)) {
            throw new RuntimeException('Не удалось восстановить каталог ' . $dir);
        }
    }

    foreach ($rootFiles as $file) {
        restore_file($backup . '/' . $file, $root . '/' . $file);
    }
    foreach ($publicFiles as $file) {
        restore_file($backup . '/public_html/' . $file, $root . '/public_html/' . $file);
    }

    $assetsSource = $backup . '/public_html/assets';
    $assetsTarget = $root . '/public_html/assets';
    if (is_dir($assetsSource)) {
        rmrf($assetsTarget);
        if (! @rename($assetsSource, $assetsTarget)) {
            throw new RuntimeException('Не удалось восстановить ассеты.');
        }
    }

    $down = $root . '/storage/framework/down';
    if (is_file($down)) {
        @unlink($down);
    }

    // Метка нужна, чтобы тик и админка видели: откат сделан аварийным контуром.
    $markerDir = $root . '/storage/framework/updates';
    ensure_dir($markerDir);
    @file_put_contents(
        $markerDir . '/recovered-' . (int) $id . '.json',
        json_encode(['update_id' => (int) $id, 'recovered_at' => date('c')], JSON_UNESCAPED_UNICODE),
    );

    echo "OK: восстановлено обновление #{$id}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ОШИБКА: ' . $e->getMessage() . "\n");
    exit(1);
}
