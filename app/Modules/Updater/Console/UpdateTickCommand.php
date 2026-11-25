<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Console;

use Illuminate\Console\Command;
use Nabilet\Modules\Updater\Services\UpdateOrchestrator;

/**
 * Один шаг конечного автомата обновления.
 *
 * Регистрируется явно в `bootstrap/app.php::withCommands()` и вызывается раз в
 * минуту из `routes/console.php`. Причина отдельной команды, а не queue job:
 * на шаред-хостинге воркер очереди выходит через `--max-time=50`, а обновление
 * может идти дольше. Команда делает ровно один переход и завершается — даже если
 * прервать её на середине, следующий тик продолжит с записанного статуса.
 */
final class UpdateTickCommand extends Command
{
    /** @var string */
    protected $signature = 'nabilet:update:tick';

    /** @var string */
    protected $description = 'Один шаг обновления системы: подготовка, замена файлов, миграции, проверка или откат';

    public function handle(UpdateOrchestrator $orchestrator): int
    {
        $result = $orchestrator->tick();

        if (! $result['handled']) {
            $this->line('Нет активных обновлений.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Обновление #%d: %s%s',
            (int) ($result['update_id'] ?? 0),
            (string) ($result['status'] ?? 'unknown'),
            isset($result['message']) ? ' — ' . (string) $result['message'] : '',
        ));

        return self::SUCCESS;
    }
}
