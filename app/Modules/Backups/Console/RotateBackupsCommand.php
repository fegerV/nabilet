<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Backups\Services\YandexDiskBackupService;

/**
 * Ручная ротация резервных копий (ТЗ §72).
 *
 * `backup:run` чистит старое сам после успешной выгрузки, но иногда нужно:
 *   - посмотреть, что БУДЕТ удалено, до удаления (`--dry-run`);
 *   - почистить копии, накопившиеся до появления этой политики;
 *   - снизить хранение задним числом (`--keep=3`).
 *
 * Очереди независимы: полные архивы и дампы БД не вытесняют друг друга.
 * Удаляются только файлы, чьё имя соответствует формату бэкапа; всё
 * постороннее в каталоге остаётся.
 */
final class RotateBackupsCommand extends Command
{
    protected $signature = 'backup:rotate
        {--keep= : Сколько последних копий хранить в каждой очереди (по умолчанию NABILET_BACKUP_KEEP)}
        {--dry-run : Показать, что будет удалено, но ничего не удалять}
        {--local-only : Только локальные копии}
        {--remote-only : Только копии на Яндекс.Диске}';

    protected $description = 'Удалить старые резервные копии, оставив заданное число последних';

    public function handle(): int
    {
        $keep = $this->rotationKeep();
        $dryRun = (bool) $this->option('dry-run');

        $doLocal = ! (bool) $this->option('remote-only');
        $doRemote = ! (bool) $this->option('local-only');

        if ($doRemote && (string) (config('services.yandex_disk.oauth_token') ?? '') === '') {
            $this->error('YANDEX_DISK_OAUTH_TOKEN не задан — ротация на Диске невозможна.');
            $this->line('Используйте --local-only, чтобы почистить только локальные копии.');

            return self::FAILURE;
        }

        /** @var YandexDiskBackupService $service */
        $service = app(YandexDiskBackupService::class);

        $failed = 0;

        if ($doLocal) {
            $result = $service->rotateLocalBackups($keep, [], $dryRun);
            $failed += $this->report('локальных копий', $result, $dryRun);
        }

        if ($doRemote) {
            try {
                $result = $service->rotateRemoteBackups($keep, [], $dryRun);
                $failed += $this->report('копий на Яндекс.Диске', $result, $dryRun);
            } catch (\Throwable $e) {
                Log::error('Remote backup rotation failed', ['exception' => $e->getMessage()]);
                $this->error('Ротация на Яндекс.Диске не удалась: ' . $e->getMessage());

                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function rotationKeep(): int
    {
        $value = $this->option('keep');

        if ($value === null || $value === '') {
            $value = config('nabilet.backup.keep', 7);
        }

        if (! is_numeric($value) || (int) $value < 0) {
            throw new \InvalidArgumentException('--keep должен быть целым числом не меньше нуля.');
        }

        return (int) $value;
    }

    /**
     * @param  array{deleted: list<string>, failed: array<string, string>, kept: int}  $result
     * @return int число файлов, которые не удалось удалить
     */
    private function report(string $where, array $result, bool $dryRun): int
    {
        if ($result['deleted'] === []) {
            $this->line("Ротация {$where}: удалять нечего (оставлено {$result['kept']}).");
        } else {
            $verb = $dryRun ? 'будет удалено' : 'удалено';

            $this->line(sprintf(
                'Ротация %s: %s %d, оставлено %d.',
                $where,
                $verb,
                count($result['deleted']),
                $result['kept'],
            ));

            foreach ($result['deleted'] as $name) {
                $this->line('  - ' . $name);
            }
        }

        foreach ($result['failed'] as $name => $error) {
            Log::warning('Backup rotation: cannot delete file', ['file' => $name, 'where' => $where, 'error' => $error]);
            $this->warn("Не удалось удалить {$name}: {$error}");
        }

        return count($result['failed']);
    }
}
