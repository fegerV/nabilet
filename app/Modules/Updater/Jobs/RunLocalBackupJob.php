<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Backups\Services\YandexDiskBackupService;
use Nabilet\Modules\Updater\Services\LocalBackupStatus;

/**
 * Локальный полный бэкап перед обновлением, когда Диск не подключён.
 *
 * В HTTP и в тике планировщика дамп не делается: `mysqldump` на большой базе
 * живёт минутами. Job ставится в общую очередь, которую разбирает тот же cron.
 */
final class RunLocalBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $updateId,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(YandexDiskBackupService $service, LocalBackupStatus $status): void
    {
        $status->recordQueued($this->updateId);

        $encrypt = (string) config('nabilet.backup.encryption_key', '') !== '';

        try {
            $result = $service->createFullBackupLocal($encrypt);
            $status->recordSuccess($this->updateId, (string) $result['name']);

            Log::channel('security')->info('Локальный бэкап перед обновлением создан', [
                'update_id' => $this->updateId,
                'file' => $result['name'],
                'user_id' => $this->userId,
            ]);
        } catch (\Throwable $e) {
            $status->recordFailed($this->updateId, $e->getMessage());
            Log::error('Локальный бэкап перед обновлением не удался', [
                'update_id' => $this->updateId,
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
