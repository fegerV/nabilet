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
        {--keep-local : Не удалять локальную копию после выгрузки}';

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

        try {
            $result = $this->option('database-only')
                ? $this->backupDatabaseOnly($service)
                : $service->createFullBackup();
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
    private function backupDatabaseOnly(YandexDiskBackupService $service): array
    {
        $path = $service->createDatabaseBackup();

        $remoteDir = rtrim((string) config('services.yandex_disk.backups_path', '/backups'), '/');

        $result = $service->uploadToYandexDisk($path, $remoteDir . '/' . basename($path));

        if ((bool) $this->option('keep-local')) {
            // `uploadToYandexDisk` удаляет локальный файл после успешной выгрузки;
            // здесь восстанавливать его нечем — поэтому предупреждаем явно, а не
            // молча игнорируем флаг.
            $this->warn('--keep-local не поддерживается для --database-only: локальный файл удаляется после выгрузки.');
        }

        return $result;
    }
}
