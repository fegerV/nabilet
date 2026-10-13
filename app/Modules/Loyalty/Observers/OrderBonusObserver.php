<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Observers;

use Nabilet\Modules\Loyalty\Jobs\AccrueOrderBonusJob;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Реакция на оплату заказа: начислить кэшбэк покупателю.
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ВЫЗОВ ИЗ `OrderService::markPaid()`
 *   Заказ становится `paid` из нескольких мест — `OrderService::markPaid()`
 *   (обычный путь оплаты), `PaymentService` при подтверждении платежа,
 *   `RefundService` и админские сценарии, — и начислять бонусы из каждого
 *   значит гарантированно забыть один. Ровно это рассуждение записано в
 *   `OrderObserver` для писем и вебхуков, и здесь оно то же: обсервер — это
 *   единственная точка, где ловятся ВСЕ переходы статуса.
 *
 * ПОЧЕМУ `afterCommit()` НА ПОСТАНОВКЕ
 *   Начисление не должно уйти от транзакции, которая откатилась: иначе
 *   покупатель получил бы бонусы за заказ, которого нет. И оно не должно
 *   выполняться внутри транзакции оплаты — сбой бонусного счёта не имеет права
 *   отменить купленный билет.
 *
 * ГРАНИЦА, КОТОРУЮ НАДО ЗНАТЬ
 *   Обсервер ловит только СМЕНУ статуса. Заказ, оказавшийся `paid` без правки
 *   строки (такого пути в коде нет — `markPaid()` всегда делает `update()`),
 *   остался бы без начисления; разбор таких случаев — задача сверки, а не
 *   наблюдателя.
 */
class OrderBonusObserver
{
    public function updated(Order $order): void
    {
        // Тот же статус мог быть записан повторно (идемпотентный `markPaid`) —
        // начисление это не повод дублировать, и `dedupe_key` поймал бы повтор,
        // но лишняя джоба в очереди всё равно не нужна.
        if (! $order->wasChanged('status')) {
            return;
        }

        if ($order->status !== OrderStateMachine::PAID) {
            return;
        }

        AccrueOrderBonusJob::forOrder($order)
            ->onConnection('database')
            ->afterCommit();
    }
}
