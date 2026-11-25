<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

/**
 * Долговечный журнал обновления (не зависит от Laravel, БД и vendor).
 *
 * Файл дописывается построчно под flock и переживает SIGKILL, перезапуск PHP
 * и потерю соединения с БД. Каждая запись фиксирует шаг ДО его выполнения и
 * отдельной записью — ПОСЛЕ. Откат восстановит только те пути, для которых
 * есть подтверждённая запись «было → стало», и никогда не удалит файл,
 * если нет доказательства, что он появился в результате обновления.
 */
final class UpdateJournal
{
    public function __construct(private readonly UpdateStorage $storage) {}

    /**
     * @param  array<string, mixed>  $meta  from_version, to_version, source, artifact_sha256
     */
    public function begin(int $updateId, array $meta): void
    {
        $this->append($updateId, [
            'seq' => 0,
            'op' => 'begin',
            'status' => 'started',
            'from_version' => (string) ($meta['from_version'] ?? ''),
            'to_version' => (string) ($meta['to_version'] ?? ''),
            'source' => (string) ($meta['source'] ?? ''),
            'artifact_sha256' => (string) ($meta['artifact_sha256'] ?? ''),
            'time' => time(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(int $updateId, string $op, array $data): void
    {
        $entries = $this->entries($updateId);
        $seq = count($entries) + 1;
        $this->append($updateId, [
            'seq' => $seq,
            'op' => $op,
            'status' => (string) ($data['status'] ?? 'pending'),
            'update_id' => $updateId,
            'time' => time(),
        ] + $data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(int $updateId): array
    {
        $path = $this->storage->journalFile($updateId);
        if (! is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Подтверждённые замены, которые нужно откатить в обратном порядке.
     *
     * @return list<array<string, mixed>>
     */
    public function reversibleEntries(int $updateId): array
    {
        $entries = $this->entries($updateId);
        $out = [];
        foreach ($entries as $entry) {
            if (($entry['status'] ?? null) !== 'done') {
                continue;
            }
            if (in_array((string) ($entry['op'] ?? ''), ['backup_dir', 'backup_file', 'swap_dir', 'swap_file', 'assets'], true)) {
                $out[] = $entry;
            }
        }

        return array_reverse($out);
    }

    public function migrationApplied(int $updateId): bool
    {
        foreach ($this->entries($updateId) as $entry) {
            if (($entry['op'] ?? null) === 'migrate' && ($entry['status'] ?? null) === 'done') {
                return true;
            }
        }

        return false;
    }

    public function completed(int $updateId): bool
    {
        foreach ($this->entries($updateId) as $entry) {
            if (($entry['op'] ?? null) === 'complete' && ($entry['status'] ?? null) === 'done') {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $entry */
    private function append(int $updateId, array $entry): void
    {
        $path = $this->storage->journalFile($updateId);
        $this->storage->ensureDirectories($updateId);
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть журнал обновления: ' . $path);
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Не удалось заблокировать журнал обновления.');
            }
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false || fwrite($handle, $line . PHP_EOL) === false) {
                throw new \RuntimeException('Не удалось записать журнал обновления.');
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
