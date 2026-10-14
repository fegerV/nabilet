<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Loyalty\Services\RaffleService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Завести билеты розыгрышей по оплаченному заказу (фаза 5).
 *
 * ПОЧЕМУ ДЖОБА, А НЕ ВЫЗОВ ПРЯМО ИЗ ОБСЕРВЕРА
 *   Запись билета — вставка в таблицу участия, и она не должна выполняться
 *   внутри транзакции оплаты: сбой розыгрыша не имеет права отменить
 *   купленный билет, а `afterCommit()` на постановке (см. наблюдателя) не
 *   даёт джобе уйти от откатившейся транзакции. Тот же порядок приоритетов,
 *   что у `AccrueOrderBonusJob` и `RewardReferralJob`.
 *
 * ПОЧЕМУ ЗАКАЗ ПЕРЕЧИТЫВАЕТСЯ, А НЕ БЕРЁТСЯ ИЗ ОБСЕРВЕРА
 *   Между оплатой и исполнением джобы заказ могли вернуть (`refunded`).
 *   Решать должно состояние на момент записи, поэтому `handle()` читает
 *   строку заново и проверяет статус: участие заводит ОПЛАЧЕННЫЙ заказ, а
 *   не «заказ, который был оплачен когда-то».
 *
 * ПОЧЕМУ ПОВТОР БЕЗОПАСЕН
 *   `tries`/`backoff` повторяют джобу при сбое, и повтор не должен плодить
 *   билеты: `UNIQUE (raffle_id, order_id)` + ловля конфликта в
 *   `RaffleEntry::remember()`. Идемпотентность в схеме, а не в проверке
 *   «есть ли строка?» — два параллельных входа дадут одну строку.
 */
class EnterRafflesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Сбой записи повторяем: вставка идемпотентна, лишнего не случится. */
    public int $tries = 3;

    /** Минуты на восстановление БД, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $orderId,
    ) {}

    /**
     * Поставить запись билетов в очередь. Возвращается `PendingDispatch`, а не
     * джоба: вызывающий навешивает `onConnection()`/`afterCommit()` на
     * ПОСТАНОВКУ — см. `RewardReferralJob::forOrder()`.
     */
    public static function forOrder(Order $order): PendingDispatch
    {
        return self::dispatch(orderId: (int) $order->getKey());
    }

    public function handle(RaffleService $raffles): void
    {
        $order = Order::query()->find($this->orderId);

        // Заказ удалён (каскад унёс и его следы) — участвовать нечему.
        if ($order === null) {
            return;
        }

        // Заказ мог быть возвращён между оплатой и исполнением. Возврат
        // участия не отзывает (см. `RaffleService`), но и не создаёт: билет
        // заводит оплаченный заказ, а не заказ с историей оплаты.
        if ($order->status !== OrderStateMachine::PAID) {
            return;
        }

        $raffles->enterForOrder($order);
    }
}
