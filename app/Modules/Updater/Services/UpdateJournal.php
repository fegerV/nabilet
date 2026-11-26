<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Nabilet\Core\Support\SignedPayload;

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
        // array_merge, а не '+' — иначе дефолтный 'pending' перекрывал
        // переданный вызывающим статус, и все записи становились «неподтверждёнными».
        $this->append($updateId, array_merge([
            'seq' => $seq,
            'op' => $op,
            'status' => 'pending',
            'update_id' => $updateId,
            'time' => time(),
        ], $data));
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
     * Все операции, которые МОГЛИ изменить файловую систему.
     *
     * Раньше брались только `status=done`. Но процесс мог умереть после
     * `rename()` и до записи подтверждения — в этом случае unfinished-операция
     * терялась, и сайт оставался в смешанном состоянии. Теперь берём и pending,
     * устраняя дубли по (op, path): последняя запись побеждает. Фактическое
     * восстановление решает `UpdateApplier` по состоянию файлов на диске.
     *
     * @return list<array<string, mixed>>
     */
    public function reversibleEntries(int $updateId): array
    {
        $entries = $this->entries($updateId);
        $byKey = [];
        foreach ($entries as $entry) {
            $op = (string) ($entry['op'] ?? '');
            if (! in_array($op, ['backup_dir', 'backup_file', 'swap_dir', 'swap_file', 'assets'], true)) {
                continue;
            }
            $key = $op . ':' . (string) ($entry['path'] ?? '');
            $byKey[$key] = $entry + ['confirmed' => ($entry['status'] ?? null) === 'done'];
        }

        return array_reverse(array_values($byKey));
    }

    /** Состояние миграций: not_started|started|partial|completed|uncertain. */
    public function migrationState(int $updateId): string
    {
        $state = 'not_started';
        foreach ($this->entries($updateId) as $entry) {
            if (($entry['op'] ?? null) !== 'migrate') {
                continue;
            }
            $status = (string) ($entry['status'] ?? '');
            if (in_array($status, ['started', 'partial', 'done', 'completed', 'uncertain'], true)) {
                $state = $status === 'done' ? 'completed' : $status;
            }
        }
        if ($state === 'not_started' && $this->completed($updateId)) {
            return 'uncertain';
        }

        return $state;
    }

    public function migrationApplied(int $updateId): bool
    {
        return $this->migrationState($updateId) !== 'not_started';
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
            // Сериализация через владельца канонических байтов: копия флагов
            // подписи в Domain/сервисах ломает HmacSignatureCopyTest и может
            // расходиться с подписывающей стороной.
            $line = SignedPayload::encode($entry);
            if ($line === null || fwrite($handle, $line . PHP_EOL) === false) {
                throw new \RuntimeException('Не удалось записать журнал обновления.');
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
