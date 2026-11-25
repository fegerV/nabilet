<?php

declare(strict_types=1);

/**
 * Аварийный откат обновления без Laravel, vendor и базы данных.
 *
 * Читает тот же долговечный журнал `operations.jsonl`, что и UpdateApplier.
 * Восстанавливает только подтверждённые шаги; если журнала нет, действует
 * консервативно: возвращает лишь реально сохранённые копии и НИКОГДА не удаляет
 * файл, для которого нет доказательства, что он создан обновлением.
 *
 *   php storage/framework/updater/recover.php <update_id>
 */
$id = $argv[1] ?? '';
if (! is_string($id) || ctype_digit($id) === false || (int) $id < 1) {
    fwrite(STDERR, "Использование: php recover.php <numeric_update_id>\n");
    exit(1);
}
$updateId = (int) $id;

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

function restore_entry(int $updateId, array $entry, string $root, string $journalPath): void
{
    $relative = (string) ($entry['path'] ?? '');
    if ($relative === '') {
        return;
    }
    $target = $root . '/' . $relative;
    $backup = (string) ($entry['backup'] ?? '');
    $hadOriginal = ($entry['original_present'] ?? false) === true;

    if (! $hadOriginal) {
        if (is_dir($target)) {
            rmrf($target);
        } elseif (is_file($target)) {
            @unlink($target);
        }

        return;
    }

    if ($backup === '' || (! is_file($backup) && ! is_dir($backup))) {
        file_put_contents($journalPath, json_encode([
            'op' => 'rollback_gap',
            'status' => 'warning',
            'path' => $relative,
            'reason' => 'backup_missing',
            'time' => time(),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

        return;
    }

    if (is_dir($backup)) {
        if (is_dir($target)) {
            rmrf($target);
        } elseif (is_file($target)) {
            @unlink($target);
        }
        if (! @rename($backup, $target)) {
            throw new RuntimeException('Не удалось восстановить каталог ' . $relative);
        }

        return;
    }

    ensure_dir(dirname($target));
    if (! @copy($backup, $target)) {
        throw new RuntimeException('Не удалось восстановить файл ' . $relative);
    }
}

$root = find_root(__DIR__);
if ($root === null) {
    fwrite(STDERR, "Не найден корень приложения (artisan).\n");
    exit(1);
}

$baseDir = $root . '/storage/app/updates/' . $updateId;
$journalPath = $baseDir . '/operations.jsonl';
$backup = $baseDir . '/backup';

$entries = [];
if (is_file($journalPath)) {
    foreach (@file($journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entry = json_decode($line, true);
        if (! is_array($entry)) {
            continue;
        }
        if (($entry['status'] ?? null) !== 'done') {
            continue;
        }
        if (! in_array((string) ($entry['op'] ?? ''), ['swap_dir', 'swap_file', 'assets'], true)) {
            continue;
        }
        $entries[] = $entry;
    }
}
$entries = array_reverse($entries);

try {
    if ($entries !== []) {
        foreach ($entries as $entry) {
            restore_entry($updateId, $entry, $root, $journalPath);
        }
    } elseif (is_dir($backup)) {
        // Легасный путь: только реально сохранённые копии, без удаления оригиналов.
        $dirs = ['public', 'vendor', 'dist', 'routes', 'resources', 'database', 'config', 'bootstrap', 'app', 'lang'];
        foreach ($dirs as $dir) {
            $source = $backup . '/' . $dir;
            $target = $root . '/' . $dir;
            if (! is_dir($source)) {
                continue;
            }
            if (is_dir($target)) {
                rmrf($target);
            }
            if (! @rename($source, $target)) {
                throw new RuntimeException('Не удалось восстановить каталог ' . $dir);
            }
        }
        $files = ['artisan', 'composer.json', 'composer.lock', '.env.example', 'CHANGELOG.md',
            'public_html/index.php', 'public_html/robots.txt', 'public_html/favicon.svg', 'public_html/installer-tailwind.js'];
        foreach ($files as $relative) {
            $source = $backup . '/' . $relative;
            if (is_file($source)) {
                ensure_dir(dirname($root . '/' . $relative));
                if (! @copy($source, $root . '/' . $relative)) {
                    throw new RuntimeException('Не удалось восстановить ' . $relative);
                }
            }
        }
        $assetsSource = $backup . '/public_html/assets';
        if (is_dir($assetsSource)) {
            rmrf($root . '/public_html/assets');
            if (! @rename($assetsSource, $root . '/public_html/assets')) {
                throw new RuntimeException('Не удалось восстановить ассеты.');
            }
        }
    } else {
        throw new RuntimeException('Нет журнала и каталога отката для этого обновления.');
    }

    $down = $root . '/storage/framework/down';
    if (is_file($down)) {
        @unlink($down);
    }

    $markerDir = $root . '/storage/framework/updates';
    ensure_dir($markerDir);
    if (is_file($journalPath)) {
        file_put_contents($journalPath, json_encode([
            'op' => 'emergency_recovery',
            'status' => 'done',
            'update_id' => $updateId,
            'time' => time(),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    @file_put_contents(
        $markerDir . '/recovered-' . $updateId . '.json',
        json_encode(['update_id' => $updateId, 'recovered_at' => date('c')], JSON_UNESCAPED_UNICODE),
    );

    echo "OK: восстановлено обновление #{$updateId}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ОШИБКА: ' . $e->getMessage() . "\n");
    exit(1);
}
