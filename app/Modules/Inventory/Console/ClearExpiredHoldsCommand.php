<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Console;

use Illuminate\Console\Command;
use Nabilet\Modules\Inventory\Services\HoldSweeper;

/**
 * Освобождает просроченные брони мест и возвращает их в инвентарь.
 *
 * НАЗНАЧЕНИЕ (критично для шаред-хостинга)
 * ---------------------------------------
 * Покупатель выбирает место во Vue-конструкторе — сервер создаёт холд
 * (`seat_holds`). Если оплаты не было, холд должен сняться автоматически.
 * На шаред-хостинге нет постоянных воркеров, поэтому очистка идёт по Cron:
 *
 *     * * * * * /opt/php82/bin/php /home/c/.../artisan schedule:run
 *
 * ЧТО ЗАДАЁТ СРОК ЖИЗНИ ХОЛДА (частый источник путаницы)
 * -----------------------------------------------------
 * Срок холда — это КОЛОНКА `seat_holds.expires_at`, которую `CartItemService`
 * заполняет из `carts.expires_at`, то есть из `CHECKOUT_HOLD_DURATION`
 * (config/nabilet.php, по умолчанию 15 минут). Sweeper сравнивает `expires_at`
 * с текущим временем и НЕ использует никакой TTL-настройки.
 *
 * `NABILET_HOLD_TTL` (по умолчанию 600) — это ДРУГАЯ величина: она читается
 * только в `config/queue.php` как `retry_after` очереди. На длительность холда
 * она не влияет. Раньше в этом докблоке было написано обратное, и правка
 * `NABILET_HOLD_TTL` «чтобы изменить удержание» молча не давала эффекта.
 *
 * `routes/console.php` запускает эту команду каждую минуту. Без неё планировщик
 * падает целиком ("Command not defined") и места блокируются навсегда.
 *
 * Идемпотентность и параллелизм обеспечены в HoldSweeper: выборка с lockForUpdate()
 * и повторная проверка статуса внутри транзакции. Запуск нескольких экземпляров
 * одновременно безопасен.
 */
class ClearExpiredHoldsCommand extends Command
{
    /** @var string */
    protected $signature = 'seats:clear-expired
                            {--dry-run : Показать статистику, но ничего не освобождать}
                            {--stats : Показать статистику по холдам}';

    /** @var string */
    protected $description = 'Освободить просроченные брони мест и вернуть их в инвентарь';

    public function handle(HoldSweeper $sweeper): int
    {
        if ($this->option('stats') || $this->option('dry-run')) {
            $stats = $sweeper->getStats();

            $this->table(
                ['Показатель', 'Значение'],
                [
                    ['Активных броней', $stats['total_active']],
                    ['Просрочено (к снятию)', $stats['total_expired']],
                    ['Истекают в ближайшие 10 мин', $stats['expiring_soon']],
                ],
            );

            if ($this->option('dry-run')) {
                $this->warn('Режим --dry-run: брони не освобождены.');

                return self::SUCCESS;
            }
        }

        $result = $sweeper->sweep();

        $released = (int) ($result['released'] ?? 0);
        $errors = $result['errors'] ?? [];

        if ($released > 0) {
            $this->info("Освобождено просроченных броней: {$released}");
        } else {
            $this->line('Просроченных броней нет.');
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error(
                    isset($error['hold_id'])
                        ? "Бронь #{$error['hold_id']}: {$error['error']}"
                        : (string) $error['error'],
                );
            }

            // Ненулевой код нужен, чтобы сбой было видно в логе Cron на Timeweb.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
