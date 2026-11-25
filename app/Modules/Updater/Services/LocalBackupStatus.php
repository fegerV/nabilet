<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

/**
 * Статус локального full-бэкапа, сделанного перед обновлением.
 *
 * Отдельный файл нужен, чтобы тик отличал «бэкап ещё идёт» от «бэкап упал»:
 * `HostChecks::lastBackup()` видит только готовые файлы и не знает ни об ошибке,
 * ни о том, что файл появился до запуска этого обновления. Запись лежит в
 * `storage/`, то есть переживает замену кода.
 */
final class LocalBackupStatus
{
    private const FILE = 'app/updates/local-backup.json';

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $path = storage_path(self::FILE);
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public function recordQueued(int $updateId): void
    {
        $this->write([
            'update_id' => $updateId,
            'status' => 'queued',
            'at' => now()->toIso8601String(),
        ]);
    }

    public function recordSuccess(int $updateId, string $file): void
    {
        $this->write([
            'update_id' => $updateId,
            'status' => 'success',
            'file' => $file,
            'at' => now()->toIso8601String(),
        ]);
    }

    public function recordFailed(int $updateId, string $error): void
    {
        $this->write([
            'update_id' => $updateId,
            'status' => 'failed',
            'error' => mb_substr($error, 0, 500),
            'at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Успешная копия именно для этой записи об обновлении, созданная не раньше
     * её старта. Иначе старый бэкап даст ложное чувство безопасности.
     */
    public function isSuccessFor(int $updateId, \DateTimeInterface $startedAt): bool
    {
        $state = $this->latest();
        if ($state === null || (int) ($state['update_id'] ?? 0) !== $updateId
            || ($state['status'] ?? '') !== 'success') {
            return false;
        }

        $at = isset($state['at']) ? strtotime((string) $state['at']) : false;
        $file = isset($state['file']) ? (string) $state['file'] : '';

        return $at !== false
            && $at >= $startedAt->getTimestamp()
            && $file !== ''
            && is_file(storage_path('app/backups/' . basename($file)));
    }

    public function isFailedFor(int $updateId, \DateTimeInterface $startedAt): bool
    {
        $state = $this->latest();
        if ($state === null || (int) ($state['update_id'] ?? 0) !== $updateId
            || ($state['status'] ?? '') !== 'failed') {
            return false;
        }

        $at = isset($state['at']) ? strtotime((string) $state['at']) : false;

        return $at !== false && $at >= $startedAt->getTimestamp();
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $dir = storage_path('app/updates');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог статуса бэкапа.');
        }

        $path = storage_path(self::FILE);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $path);
    }
}
