<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Nabilet\Core\Errors\AppError;

/**
 * Подмена кода и откат.
 *
 * Каждый шаг пишется в долговечный журнал ДО выполнения и подтверждается
 * отдельной записью ПОСЛЕ. Откат восстанавливает только подтверждённые шаги и
 * никогда не удаляет файл, если нет доказательства, что его создало обновление.
 * Это главное отличие от прежней реализации, где отсутствие backup-файла
 * означало «удалить текущий файл» — то есть могло уничтожить оригинал.
 *
 * Все замены каталогов — `rename()` в пределах одного тома. Корневые файлы
 * копируются: оригиналы нужны для отката. `$root` — test seam.
 */
final class UpdateApplier
{
    private const CODE_DIRS = [
        'app', 'bootstrap', 'config', 'database', 'lang',
        'resources', 'routes', 'dist', 'vendor', 'public', 'scripts',
    ];

    private const ROOT_FILES = [
        'artisan', 'composer.json', 'composer.lock', '.env.example', 'CHANGELOG.md',
    ];

    private const PUBLIC_FILES = ['index.php', 'robots.txt', 'favicon.svg', 'installer-tailwind.js'];

    public function __construct(
        private readonly UpdateStorage $storage,
        private readonly UpdateJournal $journal,
    ) {
    }

    public function apply(int $updateId, ?string $root = null): void
    {
        $base = rtrim($root ?? base_path(), '/\\');
        $staging = $this->storage->staging($updateId);
        $backup = $this->storage->backup($updateId);
        $this->ensureDir($backup);

        if ($this->journal->entries($updateId) === []) {
            $this->journal->begin($updateId, [
                'from_version' => (string) config('nabilet.version', '0.0.0'),
                'to_version' => '',
            ]);
        }

        $this->maintenanceOn();

        try {
            foreach (self::CODE_DIRS as $dir) {
                $this->swapDirectory($updateId, $dir, $base . '/' . $dir, $backup . '/' . $dir, $staging . '/' . $dir);
            }

            foreach (self::ROOT_FILES as $file) {
                $this->swapFile($updateId, $file, $base . '/' . $file, $backup . '/' . $file, $staging . '/' . $file);
            }

            $this->ensureDir($base . '/bootstrap/cache');

            foreach (self::PUBLIC_FILES as $file) {
                $this->swapFile(
                    $updateId,
                    'public_html/' . $file,
                    $base . '/public_html/' . $file,
                    $backup . '/public_html/' . $file,
                    $staging . '/public_html/' . $file,
                );
            }

            $this->swapAssets($updateId, $base, $backup, $staging);
        } catch (\Throwable $e) {
            // Подмена началась — оставлять сайт в смешанном состоянии нельзя.
            $this->rollback($updateId, $base);
            $this->maintenanceOff();

            throw $e;
        }
    }

    /**
     * Откат только действительно изменённых файлов.
     *
     * При наличии журнала используется он. Для легасных обновлений без журнала
     * применяется консервативный фолбэк: восстанавливаем только то, что реально
     * лежит в backup, и НЕ удаляем файлы, чьих оригиналов нет.
     */
    public function rollback(int $updateId, ?string $root = null): void
    {
        $base = rtrim($root ?? base_path(), '/\\');
        $backup = $this->storage->backup($updateId);
        $entries = $this->journal->reversibleEntries($updateId);

        if ($entries === [] && ! is_dir($backup)) {
            $this->maintenanceOff();

            throw new AppError('Нет каталога отката для этого обновления.', 'UPDATE_NO_BACKUP', 422);
        }

        if ($entries !== []) {
            foreach ($entries as $entry) {
                $this->restoreEntry($updateId, $entry, $base);
            }
        } else {
            $this->legacyRollback($base, $backup);
        }

        $this->journal->record($updateId, 'rollback', [
            'status' => 'done',
            'restored_entries' => count($entries),
        ]);
        $this->clearCaches();
        $this->maintenanceOff();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function restoreEntry(int $updateId, array $entry, string $base): void
    {
        $relative = (string) ($entry['path'] ?? '');
        if ($relative === '') {
            return;
        }
        $target = $base . '/' . $relative;
        $backupPath = (string) ($entry['backup'] ?? '');
        $hadOriginal = ($entry['original_present'] ?? false) === true;

        if (! $hadOriginal) {
            // Файл/каталог появился в результате обновления — его можно удалить
            // только потому, что журнал это явно подтверждает.
            if (is_dir($target)) {
                $this->storage->deleteTree($target);
            } elseif (is_file($target)) {
                @unlink($target);
            }

            return;
        }

        if ($backupPath === '' || (! is_file($backupPath) && ! is_dir($backupPath))) {
            // Оригинал был, но копии нет: удалять НЕЛЬЗЯ. Оставляем файл как есть
            // и помечаем расхождение в журнале для ручного разбора.
            $this->journal->record($updateId, 'rollback_gap', [
                'status' => 'warning',
                'path' => $relative,
                'reason' => 'backup_missing',
            ]);

            return;
        }

        if (is_dir($backupPath)) {
            if (is_dir($target)) {
                $this->storage->deleteTree($target);
            } elseif (is_file($target)) {
                @unlink($target);
            }
            if (! rename($backupPath, $target)) {
                throw new AppError('Не удалось восстановить каталог ' . $relative, 'UPDATE_ROLLBACK_FAILED', 500);
            }

            return;
        }

        $this->ensureDir(dirname($target));
        if (! copy($backupPath, $target)) {
            throw new AppError('Не удалось восстановить файл ' . $relative, 'UPDATE_ROLLBACK_FAILED', 500);
        }
    }

    /** Консервативный откат без журнала: только реально сохранённые оригиналы. */
    private function legacyRollback(string $base, string $backup): void
    {
        foreach (array_reverse(self::CODE_DIRS) as $dir) {
            $source = $backup . '/' . $dir;
            $target = $base . '/' . $dir;
            if (! is_dir($source)) {
                continue;
            }
            if (is_dir($target)) {
                $this->storage->deleteTree($target);
            } elseif (is_file($target)) {
                @unlink($target);
            }
            if (! rename($source, $target)) {
                throw new AppError('Не удалось восстановить каталог ' . $dir, 'UPDATE_ROLLBACK_FAILED', 500);
            }
        }

        foreach ([...self::ROOT_FILES, ...array_map(static fn (string $f): string => 'public_html/' . $f, self::PUBLIC_FILES)] as $relative) {
            $source = $backup . '/' . $relative;
            $target = $base . '/' . $relative;
            if (is_file($source)) {
                $this->ensureDir(dirname($target));
                if (! copy($source, $target)) {
                    throw new AppError('Не удалось восстановить ' . $relative, 'UPDATE_ROLLBACK_FAILED', 500);
                }
            }
            // Раньше здесь был `elseif (is_file($target)) unlink($target)` —
            // именно он удалял оригиналы, если backup ещё не был создан.
        }

        $assetsSource = $backup . '/public_html/assets';
        $assetsTarget = $base . '/public_html/assets';
        if (is_dir($assetsSource)) {
            if (is_dir($assetsTarget)) {
                $this->storage->deleteTree($assetsTarget);
            }
            if (! rename($assetsSource, $assetsTarget)) {
                throw new AppError('Не удалось восстановить ассеты.', 'UPDATE_ROLLBACK_FAILED', 500);
            }
        }
    }

    public function maintenanceOn(): void
    {
        $this->maintenance()->activate([
            'time' => time(),
            'message' => 'Обновление системы. Пожалуйста, подождите.',
            'retry' => 30,
        ]);
    }

    public function maintenanceOff(): void
    {
        $this->maintenance()->deactivate();
    }

    public function clearCaches(): void
    {
        foreach (['cache:clear', 'config:clear', 'route:clear', 'view:clear'] as $command) {
            Artisan::call($command);
        }
    }

    private function maintenance(): MaintenanceMode
    {
        /** @var MaintenanceMode $maintenance */
        $maintenance = app(MaintenanceMode::class);

        return $maintenance;
    }

    private function swapDirectory(int $updateId, string $relative, string $target, string $backup, string $staged): void
    {
        if (! is_dir($staged)) {
            return;
        }
        $hadOriginal = is_dir($target);
        $this->journal->record($updateId, 'backup_dir', [
            'status' => 'pending',
            'path' => $relative,
            'source' => $target,
            'backup' => $backup,
            'original_present' => $hadOriginal,
            'checksum_before' => $hadOriginal ? $this->dirChecksum($target) : null,
        ]);

        if (is_dir($backup)) {
            $this->storage->deleteTree($backup);
        }
        if ($hadOriginal && ! rename($target, $backup)) {
            throw new AppError('Не удалось сохранить текущий каталог ' . $relative, 'UPDATE_SWAP_FAILED', 500);
        }
        if (! rename($staged, $target)) {
            throw new AppError('Не удалось установить каталог ' . $relative, 'UPDATE_SWAP_FAILED', 500);
        }

        $this->journal->record($updateId, 'swap_dir', [
            'status' => 'done',
            'path' => $relative,
            'source' => $staged,
            'backup' => $hadOriginal ? $backup : '',
            'target' => $target,
            'original_present' => $hadOriginal,
            'checksum_after' => $this->dirChecksum($target),
        ]);
    }

    private function swapFile(int $updateId, string $relative, string $target, string $backup, string $staged): void
    {
        $this->ensureDir(dirname($backup));
        $hadOriginal = is_file($target);
        $this->journal->record($updateId, 'backup_file', [
            'status' => 'pending',
            'path' => $relative,
            'source' => $target,
            'backup' => $backup,
            'original_present' => $hadOriginal,
            'checksum_before' => $hadOriginal ? hash_file('sha256', $target) : null,
        ]);

        if ($hadOriginal && ! copy($target, $backup)) {
            throw new AppError('Не удалось сохранить файл ' . $relative, 'UPDATE_SWAP_FAILED', 500);
        }
        if (is_file($staged) && ! copy($staged, $target)) {
            throw new AppError('Не удалось установить файл ' . $relative, 'UPDATE_SWAP_FAILED', 500);
        }

        $this->journal->record($updateId, 'swap_file', [
            'status' => 'done',
            'path' => $relative,
            'source' => $staged,
            'backup' => $hadOriginal ? $backup : '',
            'target' => $target,
            'original_present' => $hadOriginal,
            'checksum_after' => is_file($target) ? hash_file('sha256', $target) : null,
        ]);
    }

    private function swapAssets(int $updateId, string $base, string $backup, string $staging): void
    {
        $target = $base . '/public_html/assets';
        $backupPath = $backup . '/public_html/assets';
        $staged = $staging . '/public_html/assets';
        $hadOriginal = is_dir($target);

        $this->journal->record($updateId, 'backup_dir', [
            'status' => 'pending',
            'path' => 'public_html/assets',
            'source' => $target,
            'backup' => $backupPath,
            'original_present' => $hadOriginal,
            'checksum_before' => $hadOriginal ? $this->dirChecksum($target) : null,
        ]);

        if ($hadOriginal) {
            $this->ensureDir(dirname($backupPath));
            if (! rename($target, $backupPath)) {
                throw new AppError('Не удалось сохранить текущие ассеты.', 'UPDATE_SWAP_FAILED', 500);
            }
        }
        if (is_dir($staged) && ! rename($staged, $target)) {
            throw new AppError('Не удалось установить новые ассеты.', 'UPDATE_SWAP_FAILED', 500);
        }

        $this->journal->record($updateId, 'assets', [
            'status' => 'done',
            'path' => 'public_html/assets',
            'backup' => $hadOriginal ? $backupPath : '',
            'original_present' => $hadOriginal,
            'checksum_after' => is_dir($target) ? $this->dirChecksum($target) : null,
        ]);
    }

    /** Контрольная сумма каталога по относительным путям и содержимому файлов. */
    private function dirChecksum(string $dir): string
    {
        $context = hash_init('sha256');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        $paths = [];
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen(rtrim($dir, '/\\')) + 1);
            $paths[$relative] = $item->isFile() ? hash_file('sha256', $item->getPathname()) : 'dir';
        }
        ksort($paths);
        foreach ($paths as $relative => $hash) {
            hash_update($context, $relative . "\0" . $hash . "\n");
        }

        return hash_final($context);
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new AppError('Не удалось создать каталог ' . $dir, 'UPDATE_SWAP_FAILED', 500);
        }
    }
}
