<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Console;

use Illuminate\Console\Command;
use Nabilet\Modules\Orders\Services\UnpaidOrderReminderSweeper;

/**
 * Разослать напоминания о незавершённой оплате (`orders:remind-unpaid`).
 *
 * Ставится в расписание на ЧАС (`routes/console.php`): чаще — шум для
 * покупателя, который ещё держит платёжную страницу открытом; реже — бронь
 * истекает раньше письма.
 */
final class RemindUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:remind-unpaid';

    protected $description = 'Напомнить покупателям о заказах, ждущих оплаты больше часа';

    public function handle(UnpaidOrderReminderSweeper $sweeper): int
    {
        $outcome = $sweeper->remind();

        $this->info(sprintf(
            'unpaid order reminders: sent %d, failed %d',
            $outcome['sent'],
            $outcome['failed'],
        ));

        return self::SUCCESS;
    }
}
