<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Console;

use Nabilet\Modules\Backups\Services\YandexDiskBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Резервное копирование по расписанию (ТЗ §72).
 *
 * До появления этой команды `YandexDiskBackupService` не вызывался НИОТКУДА:
 * ни одной команды, ни одной задачи в `routes/console.php`. То есть контур
 * бэкапов существовал только в виде класса — отказ диска означал безвозвратную
 * потерю данных, а «резервное копирование» в документации не имело исполнителя.
 *
 * Команда намеренно возвращает ненулевой код при неудаче: планировщик и
 * мониторинг должны видеть провал, а не «тихо ничего не сделал».
 */
final class RunBackupCommand extends Command
{
    protected $signature = 'backup:run
        {--database-only : Только дамп БД, без файлов storage}
        {--keep-local : Не удалять локальную копию после выгрузки}
        {--no-encrypt : НЕ шифровать бэкап (по умолчанию шифруем, если задан ключ)}
        {--keep= : Сколько последних копий хранить в каждой очереди (по умолчанию NABILET_BACKUP_KEEP)}
        {--no-rotate : Не удалять старые копии после успешной выгрузки}';

    protected $description = 'Создать резервную копию и выгрузить её во внешнее хранилище (Яндекс.Диск)';

    public function handle(): int
    {
        $service = app(YandexDiskBackupService::class);

        $token = (string) (config('services.yandex_disk.oauth_token') ?? '');

        if ($token === '') {
            // Без внешнего хранилища бэкап на том же диске не защищает от отказа
            // этого диска. Сообщаем громко и не делаем вид, что всё хорошо.
            Log::error('Backup skipped: YANDEX_DISK_OAUTH_TOKEN is not configured');

            $this->error('YANDEX_DISK_OAUTH_TOKEN не задан — выгрузка во внешнее хранилище невозможна.');

            return self::FAILURE;
        }

        $keepLocal = (bool) $this->option('keep-local');

        // Шифрование по умолчанию: если ключ не задан — предупреждаем и идём
        // без шифрования (сохранение обратной совместимости), но громко.
        $encrypt = ! (bool) $this->option('no-encrypt');
        $hasKey = (string) (config('nabilet.backup.encryption_key') ?? '') !== '';

        if ($encrypt && ! $hasKey) {
            $this->warn('NABILET_BACKUP_ENCRYPTION_KEY не задан — бэкап пойдёт БЕЗ шифрования.');
            $encrypt = false;
        }

        try {
            $result = $this->option('database-only')
                ? $this->backupDatabaseOnly($service, $keepLocal, $encrypt)
                : $service->createFullBackup(deleteLocal: ! $keepLocal, encrypt: $encrypt);
        } catch (\Throwable $e) {
            Log::error('Backup failed', [
                'exception' => $e->getMessage(),
                'database_only' => (bool) $this->option('database-only'),
            ]);

            $this->error('Резервное копирование не удалось: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Бэкап создан и выгружен: ' . (string) ($result['remote_path'] ?? '—'));

        // Ротация — ПОСЛЕ успешной выгрузки. До неё удалять старое нельзя:
        // если диск недоступен и новый бэкап не создан, старая копия остаётся
        // единственной. Сбой самой ротации не отменяет успешный бэкап — это
        // отдельная задача сопровождения, поэтому пишем предупреждение, а не
        // роняем команду.
        if (! (bool) $this->option('no-rotate')) {
            try {
                $this->rotate(
                    $service,
                    $this->rotationKeep(),
                    basename((string) ($result['remote_path'] ?? '')),
                );
            } catch (\Throwable $e) {
                Log::warning('Backup rotation failed', ['exception' => $e->getMessage()]);
                $this->warn('Ротация старых копий не удалась: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Сколько последних копий оставлять в каждой очереди.
     */
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
     * Удалить старые копии локально и на Яндекс.Диске.
     *
     * Только что выгруженный файл защищён явно: даже при `--keep=0` он
     * остаётся. Это последняя линия обороны против политики, которая однажды
     * окажется слишком агрессивной.
     */
    private function rotate(YandexDiskBackupService $service, int $keep, string $currentFile): void
    {
        $protect = $currentFile !== '' ? [$currentFile] : [];

        $local = $service->rotateLocalBackups($keep, $protect);
        $this->reportRotation('локальных копий', $local);

        $remote = $service->rotateRemoteBackups($keep, $protect);
        $this->reportRotation('копий на Яндекс.Диске', $remote);
    }

    /**
     * @param  array{deleted: list<string>, failed: array<string, string>, kept: int}  $result
     */
    private function reportRotation(string $where, array $result): void
    {
        if ($result['deleted'] === []) {
            $this->line("Ротация {$where}: удалять нечего.");
        } else {
            $this->line(sprintf(
                'Ротация %s: удалено %d, оставлено %d.',
                $where,
                count($result['deleted']),
                $result['kept'],
            ));
        }

        foreach ($result['failed'] as $name => $error) {
            Log::warning('Backup rotation: cannot delete file', ['file' => $name, 'where' => $where, 'error' => $error]);
            $this->warn("Не удалось удалить {$name}: {$error}");
        }
    }

    /**
     * @return array{success: bool, remote_path: string|null, url: string|null}
     */
    private function backupDatabaseOnly(YandexDiskBackupService $service, bool $keepLocal, bool $encrypt): array
    {
        $path = $service->createDatabaseBackup($encrypt);

        $remoteDir = rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/');

        return $service->uploadToYandexDisk(
            $path,
            $remoteDir . '/' . basename($path),
            deleteLocal: ! $keepLocal,
        );
    }
}
