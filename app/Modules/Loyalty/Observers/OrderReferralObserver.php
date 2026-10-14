<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Observers;

use Nabilet\Modules\Loyalty\Jobs\RewardReferralJob;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Реакция на оплату заказа: награда пригласившему и другу (фаза 4).
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ВЫЗОВ ИЗ СЦЕНАРИЯ ОПЛАТЫ
 *   Заказ становится `paid` из нескольких мест (подробнее — в
 *   `OrderBonusObserver`): `OrderService::markPaid()`, подтверждение платежа,
 *   админские сценарии. Реферальная награда привязана к ЭТОМУ событию, а не к
 *   пути, которым оно наступило, иначе один из путей платил бы, а остальные
 *   нет. Это четвёртый наблюдатель на `Order` (письма/вебхуки, бонусы,
 *   уровни); Laravel вызывает все.
 *
 * ПОЧЕМУ ТОЛЬКО `paid`
 *   Награда полагается за первую ОПЛАТУ друга. Неоплаченный заказ
 *   (`cancelled`, `expired`, `payment_failed`) награды не даёт, и снимать её
 *   с оплаченного заказа тоже нечему: возврат награду не отзывает
 *   (`docs/LOYALTY-PLAN.md`), поэтому переходы «назад» здесь не нужны вовсе.
 *
 * ПОЧЕМУ `afterCommit()` НА ПОСТАВКЕ
 *   Награда — запись в две таблицы, и она не должна уходить от транзакции,
 *   которая откатилась: иначе бонусы начислялись бы за заказ, которого нет.
 *   И она не должна выполняться внутри транзакции оплаты — сбой рефералки не
 *   имеет права отменить купленный билет. Ровно те же причины, что у
 *   `OrderBonusObserver`.
 */
class OrderReferralObserver
{
    public function updated(Order $order): void
    {
        // Тот же статус мог быть записан повторно (идемпотентный `markPaid`) —
        // лишняя джоба в очереди не нужна, хотя `rewardForOrder()` повтор и
        // пережил бы.
        if (! $order->wasChanged('status')) {
            return;
        }

        if ($order->status !== OrderStateMachine::PAID) {
            return;
        }

        RewardReferralJob::forOrder($order)
            ->onConnection('database')
            ->afterCommit();
    }
}
