<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Console;

use Illuminate\Console\Command;
use Nabilet\Modules\Cart\Services\AbandonedCartReminderSweeper;

/**
 * Напомнить авторизованным покупателям о корзинах, чья бронь истекает
 * (`carts:remind-abandoned`). Расписание — раз в 5 минут (см.
 * `routes/console.php`): TTL корзины 15 минут, почасовой прогон приходил бы
 * к мёртвой корзине.
 */
final class RemindAbandonedCartsCommand extends Command
{
    protected $signature = 'carts:remind-abandoned';

    protected $description = 'Напомнить авторизованным покупателям о корзинах, чья бронь истекает';

    public function handle(AbandonedCartReminderSweeper $sweeper): int
    {
        $outcome = $sweeper->remind();

        // ВРЕМЕННАЯ диагностика: свип зелёный локально и не выбирает корзины
        // на CI. Убирается после выявления расхождения.
        fwrite(STDOUT, sprintf(
            "::warning::carts-remind window=[%s .. %s] sent=%d failed=%d\n",
            $outcome['debug_window_low'],
            $outcome['debug_window_high'],
            $outcome['sent'],
            $outcome['failed'],
        ));

        $this->info(sprintf(
            'abandoned cart reminders: sent %d, failed %d',
            $outcome['sent'],
            $outcome['failed'],
        ));

        return self::SUCCESS;
    }
}
