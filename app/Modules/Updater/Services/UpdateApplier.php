<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Nabilet\Core\Errors\AppError;

/**
 * Подмена кода и откат.
 *
 * Все операции — `rename()` в пределах одного тома, поэтому каждый каталог
 * меняется за одну системную операцию. Между шагами сайт закрыт режимом
 * обслуживания. Корневые файлы копируются (не переименовываются), потому что
 * original-ы нужны для отката. `$root` — test seam: в unit-тестах подменяется
 * временным каталогом, реальный код приложения в тестах не трогается.
 */
final class UpdateApplier
{
    /** Каталоги, принадлежащие релизу. */
    private const CODE_DIRS = [
        'app', 'bootstrap', 'config', 'database', 'lang',
        'resources', 'routes', 'dist', 'vendor', 'public',
    ];

    /** Корневые файлы релиза (кроме .env — он священен). */
    private const ROOT_FILES = [
        'artisan', 'composer.json', 'composer.lock', '.env.example', 'CHANGELOG.md',
    ];

    /** Публичные файлы, которые разрешено обновлять. */
    private const PUBLIC_FILES = ['index.php', 'robots.txt', 'favicon.svg', 'installer-tailwind.js'];

    public function __construct(
        private readonly UpdateStorage $storage,
    ) {
    }

    public function apply(int $updateId, ?string $root = null): void
    {
        $base = rtrim($root ?? base_path(), '/\\');
        $staging = $this->storage->staging($updateId);
        $backup = $this->storage->backup($updateId);
        $this->ensureDir($backup);

        $this->maintenanceOn();

        try {
            foreach (self::CODE_DIRS as $dir) {
                $this->swapDirectory($base . '/' . $dir, $backup . '/' . $dir, $staging . '/' . $dir);
            }

            foreach (self::ROOT_FILES as $file) {
                $this->swapFile($base . '/' . $file, $backup . '/' . $file, $staging . '/' . $file);
            }

            // bootstrap/cache создаётся заново из staging (там лежит .gitignore).
            // Старые скомпилированные config/routes/view удаляются следующим тиком
            // через `config:clear`/`route:clear`/`view:clear`.
            $this->ensureDir($base . '/bootstrap/cache');

            foreach (self::PUBLIC_FILES as $file) {
                $this->swapFile(
                    $base . '/public_html/' . $file,
                    $backup . '/public_html/' . $file,
                    $staging . '/public_html/' . $file,
                );
            }

            // Ассеты витрины имеют хэши в именах, поэтому старые файлы можно
            // не удалять: они перестанут упоминаться в новом index.html.
            $assetsTarget = $base . '/public_html/assets';
            $assetsBackup = $backup . '/public_html/assets';
            $assetsStage = $staging . '/public_html/assets';
            if (is_dir($assetsTarget)) {
                $this->ensureDir(dirname($assetsBackup));
                if (! rename($assetsTarget, $assetsBackup)) {
                    throw new AppError('Не удалось сохранить текущие ассеты.', 'UPDATE_SWAP_FAILED', 500);
                }
            }
            if (is_dir($assetsStage) && ! rename($assetsStage, $assetsTarget)) {
                throw new AppError('Не удалось установить новые ассеты.', 'UPDATE_SWAP_FAILED', 500);
            }
        } catch (\Throwable $e) {
            // Подмена началась — оставлять сайт в смешанном состоянии нельзя.
            // Откат здесь делается в том же процессе: он touches только файлы.
            $this->rollback($updateId, $base);
            $this->maintenanceOff();

            throw $e;
        }
    }

    public function rollback(int $updateId, ?string $root = null): void
    {
        $base = rtrim($root ?? base_path(), '/\\');
        $backup = $this->storage->backup($updateId);

        if (! is_dir($backup)) {
            $this->maintenanceOff();

            throw new AppError('Нет каталога отката для этого обновления.', 'UPDATE_NO_BACKUP', 422);
        }

        foreach (array_reverse(self::CODE_DIRS) as $dir) {
            $source = $backup . '/' . $dir;
            $target = $base . '/' . $dir;
            if (! is_dir($source)) {
                continue;
            }
            if (is_dir($target)) {
                $this->storage->deleteTree($target);
            }
            if (! rename($source, $target)) {
                throw new AppError('Не удалось восстановить каталог ' . $dir, 'UPDATE_ROLLBACK_FAILED', 500);
            }
        }

        foreach (self::ROOT_FILES as $file) {
            $source = $backup . '/' . $file;
            $target = $base . '/' . $file;
            if (is_file($source)) {
                if (! copy($source, $target)) {
                    throw new AppError('Не удалось восстановить файл ' . $file, 'UPDATE_ROLLBACK_FAILED', 500);
                }
            } elseif (is_file($target)) {
                unlink($target);
            }
        }

        foreach (self::PUBLIC_FILES as $file) {
            $source = $backup . '/public_html/' . $file;
            $target = $base . '/public_html/' . $file;
            if (is_file($source)) {
                if (! copy($source, $target)) {
                    throw new AppError('Не удалось восстановить ' . $file, 'UPDATE_ROLLBACK_FAILED', 500);
                }
            } elseif (is_file($target)) {
                unlink($target);
            }
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

        $this->clearCaches();
        $this->maintenanceOff();
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

    private function swapDirectory(string $target, string $backup, string $staged): void
    {
        if (! is_dir($staged)) {
            return;
        }
        if (is_dir($backup)) {
            $this->storage->deleteTree($backup);
        }
        if (is_dir($target) && ! rename($target, $backup)) {
            throw new AppError('Не удалось сохранить текущий каталог ' . basename($target), 'UPDATE_SWAP_FAILED', 500);
        }
        if (! rename($staged, $target)) {
            throw new AppError('Не удалось установить каталог ' . basename($target), 'UPDATE_SWAP_FAILED', 500);
        }
    }

    private function swapFile(string $target, string $backup, string $staged): void
    {
        $this->ensureDir(dirname($backup));
        if (is_file($target) && ! copy($target, $backup)) {
            throw new AppError('Не удалось сохранить файл ' . basename($target), 'UPDATE_SWAP_FAILED', 500);
        }
        if (is_file($staged) && ! copy($staged, $target)) {
            throw new AppError('Не удалось установить файл ' . basename($target), 'UPDATE_SWAP_FAILED', 500);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new AppError('Не удалось создать каталог ' . $dir, 'UPDATE_SWAP_FAILED', 500);
        }
    }
}
