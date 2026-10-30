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
        {--no-encrypt : НЕ шифровать бэкап (по умолчанию шифруем, если задан ключ)}';

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

        return self::SUCCESS;
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
