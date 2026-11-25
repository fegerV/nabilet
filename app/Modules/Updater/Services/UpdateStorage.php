<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

/**
 * Единственный владелец путей обновления.
 *
 * И архив, и staging, и backup-каталог лежат в `storage/app/updates` — внутри
 * каталога сайта, но вне DocumentRoot и вне набора каталогов, которые меняет
 * applier. Это важно по двум причинам: `rename()` между ними остаётся
 * атомарным (один том), а состояние переживает замену кода.
 */
final class UpdateStorage
{
    private const ROOT = 'app/updates';

    /** Дополнительный guard для вложенных вызовов в одном PHP-процессе. */
    private static bool $lockHeld = false;

    public function root(): string
    {
        return storage_path(self::ROOT);
    }

    public function uploads(): string
    {
        return $this->root() . '/uploads';
    }

    public function base(int $updateId): string
    {
        return $this->root() . '/' . max(1, $updateId);
    }

    public function archive(int $updateId, string $artifact): string
    {
        return $this->base($updateId) . '/' . basename($artifact);
    }

    public function staging(int $updateId): string
    {
        return $this->base($updateId) . '/site';
    }

    public function backup(int $updateId): string
    {
        return $this->base($updateId) . '/backup';
    }

    public function stateFile(int $updateId): string
    {
        return $this->base($updateId) . '/state.json';
    }

    /** Append-only журнал операций: переживает SIGKILL и недоступность БД. */
    public function journalFile(int $updateId): string
    {
        return $this->base($updateId) . '/operations.jsonl';
    }

    public function globalLockFile(): string
    {
        return storage_path('framework/updates/update.lock');
    }

    /**
     * Глобальная эксклюзивная блокировка обновления.
     *
     * Неблокирующий захват: если другой процесс уже обновляется, вызывающий
     * получает false, а не ждёт вечно. Файл живёт в storage — вне заменяемых
     * каталогов.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T|null  null — блокировка занята
     */
    public function withGlobalLock(callable $callback): mixed
    {
        if (self::$lockHeld) {
            return null;
        }
        $dir = dirname($this->globalLockFile());
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }
        $handle = @fopen($this->globalLockFile(), 'c+b');
        if ($handle === false) {
            return null;
        }
        self::$lockHeld = true;
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                self::$lockHeld = false;

                return null;
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            self::$lockHeld = false;
        }
    }

    public function heartbeatFile(): string
    {
        return storage_path('framework/updates/tick.marker');
    }

    public function recoveryScript(): string
    {
        return storage_path('framework/updater/recover.php');
    }

    public function ensureDirectories(int $updateId): void
    {
        $dirs = [$this->uploads(), dirname($this->heartbeatFile())];
        if ($updateId > 0) {
            $dirs[] = $this->base($updateId);
        }

        foreach ($dirs as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Не удалось создать каталог обновления: ' . $dir);
            }
        }
    }

    /**
     * Аварийный скрипт отката лежит в `storage/` — applier его не трогает.
     * Копируется при старте обновления, до любой подмены файлов.
     */
    public function ensureRecoveryScript(): void
    {
        $target = $this->recoveryScript();
        $source = base_path('scripts/updater-recover.php');
        if (! is_file($source)) {
            throw new \RuntimeException('Не удалось найти исходник аварийного отката.');
        }

        // Раньше existence проверялась только по is_file: устаревшая копия
        // оставалась навсегда и восстанавливала файлы по старым правилам.
        $sourceHash = hash_file('sha256', $source);
        if (is_file($target) && $sourceHash !== false && hash_file('sha256', $target) === $sourceHash) {
            return;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог аварийного отката.');
        }

        $tmp = $target . '.tmp';
        if (! copy($source, $tmp) || ! rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось обновить скрипт аварийного отката.');
        }
    }

    /** @param array<string, mixed> $state */
    public function writeState(int $updateId, array $state): void
    {
        // writeState вызывается и для ещё не созданной записи: база в этот момент
        // может быть недоступна, а состояние всё равно должно быть записано.
        $this->ensureDirectories(0);
        $this->ensureDirectories($updateId);
        $path = $this->stateFile($updateId);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $path);
    }

    /** @return array<string, mixed>|null */
    public function readState(int $updateId): ?array
    {
        $path = $this->stateFile($updateId);
        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : null;
    }

    public function touchHeartbeat(): void
    {
        $path = $this->heartbeatFile();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }
        file_put_contents($path, (string) time(), LOCK_EX);
    }

    public function heartbeatFresh(int $maxAgeSeconds = 180): bool
    {
        $path = $this->heartbeatFile();

        return is_file($path) && (time() - (int) filemtime($path)) <= $maxAgeSeconds;
    }

    /**
     * Удаляет старые рабочие каталоги, сохраняя указанное число последних.
     * Старый набор каталогов нужен для кнопки «Откатить», поэтому удаление
     * идёт только после успешного завершения и только сверх лимита.
     */
    public function prune(int $keep): void
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return;
        }

        $dirs = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
            if (ctype_digit($name)) {
                $dirs[] = [(int) $name, $path];
            }
        }
        usort($dirs, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach (array_slice($dirs, max(0, $keep)) as [, $path]) {
            $this->deleteTree($path);
        }
    }

    public function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $item->getPathname();
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($target);
            } else {
                unlink($target);
            }
        }
        rmdir($path);
    }
}
