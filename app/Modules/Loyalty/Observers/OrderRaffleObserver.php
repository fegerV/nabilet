<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Observers;

use Nabilet\Modules\Loyalty\Jobs\EnterRafflesJob;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Реакция на оплату заказа: билеты розыгрышей «счастливый билет» (фаза 5).
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ВЫЗОВ ИЗ СЦЕНАРИЯ ОПЛАТЫ
 *   Заказ становится `paid` из нескольких мест (`OrderService::markPaid()`,
 *   подтверждение платежа, админские сценарии) — тот же аргумент, что у
 *   `OrderBonusObserver`, `OrderLoyaltyObserver` и `OrderReferralObserver`.
 *   Участие привязано к СОБЫТИЮ «заказ оплачен», а не к пути, которым оно
 *   наступило, иначе один из путок платил бы, а остальные нет. Это пятый
 *   наблюдатель на `Order`; Laravel вызывает все.
 *
 * ПОЧЕМУ ТОЛЬКО `paid`
 *   Билет заводит оплаченный заказ. Неоплаченный (`cancelled`, `expired`,
 *   `payment_failed`) участия не даёт, а «назад» из `paid` участия не
 *   отзывает: возврат заказа НЕ удаляет билет (см. `RaffleService`) —
 *   поэтому переходы «из paid» здесь не нужны вовсе.
 *
 * ПОЧЕМУ `wasChanged('status')`
 *   Идемпотентный `markPaid()` может записать тот же статус повторно, и
 *   лишняя джоба в очереди не нужна. Повтор она и так пережила бы:
 *   `UNIQUE (raffle_id, order_id)` не даст второго билета.
 */
class OrderRaffleObserver
{
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        if ($order->status !== OrderStateMachine::PAID) {
            return;
        }

        EnterRafflesJob::forOrder($order)
            ->onConnection('database')
            ->afterCommit();
    }
}
