<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Backups\Services\YandexDiskSettings;

/**
 * Тяжёлый бэкап вне HTTP-запроса.
 *
 * Веб-контроллер только ставит эту задачу в очередь: mysqldump и загрузка
 * на Диск живут дольше любого HTTP-таймаута. На шаред-хостинге очередь
 * разбирается по крону (`queue:work --stop-when-empty`), поэтому кнопка
 * в админке честно сообщает «задача принята», а результат виден в статусе.
 */
final class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly string $mode,
        public readonly ?int $userId = null,
    ) {}

    public function handle(YandexDiskSettings $settings): void
    {
        try {
            $exitCode = Artisan::call('backup:run', [
                '--mode' => $this->mode,
            ]);
            $output = trim(Artisan::output());
            $ok = $exitCode === 0;

            $settings->recordRun(
                $this->mode,
                $ok ? 'success' : 'failed',
                $output !== '' ? $output : ($ok ? 'Бэкап создан.' : 'Команда завершилась с ошибкой.'),
            );

            $context = [
                'mode' => $this->mode,
                'user_id' => $this->userId,
                'exit_code' => $exitCode,
                'output' => mb_substr($output, 0, 1000),
            ];
            if ($ok) {
                Log::channel('security')->info('Backup job finished', $context);
            } else {
                Log::error('Backup job finished with failure', $context);
            }
        } catch (\Throwable $e) {
            $settings->recordRun($this->mode, 'failed', $e->getMessage());
            Log::error('Backup job threw an exception', [
                'mode' => $this->mode,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
