<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\Services\RefundService;

/**
 * Отмена события с возвратом денег покупателям (аудиты D-4/S11, R-08).
 *
 * БЫЛО
 *   Отмена события при проданных билетах запрещалась политикой HAS_SOLD_UNITS,
 *   а штурмового инструмента «settle first» не существовало: билеты оставались
 *   валидными на несостоявшееся мероприятие, деньги зависали, процесса не было
 *   вообще. Обход — только прямая правка БД мимо машин состояний.
 *
 * КАК ЭТО РАБОТАЕТ
 *   1. Находим ЗАКАЗЫ события по той же цепочке, которой считает продажи
 *      `EventPublicationService::soldUnits()` (order_items → inventory_items →
 *      sessions.event_id), а не по денормализованному `orders.event_id` — он
 *      заполняется не для всех исторических заказов.
 *   2. По каждому заказу — полный возврат каждого успешного платежа через
 *      штатный `RefundService::refund()`: деньги у провайдера, билеты отзывает
 *      `RefundLedger`, письмо уходит по смене статуса заказа
 *      (`OrderObserver` → очередь).
 *   3. Отказ одного заказа НЕ останавливает каскад: успехи фиксируются, failures
 *      возвращаются списком для оператора (повторный вызов идемпотентен —
 *      уже возвращённые заказы были бы отфильтрованы статусами).
 *
 * ПОСЛЕ ЭТОГО обычная отмена события проходит сама: `soldUnits()` исключает
 * заказы со статусами `cancelled`/`refunded`.
 */
final class EventOrderRefunder
{
    public function __construct(
        private readonly RefundService $refunds,
    ) {}

    /**
     * Вернуть деньги по всем оплаченным заказам события.
     *
     * @param  string $reason человекочитаемая причина — уходит в refund-строку
     * @return array{refunded: int, refund_failures: list<array{order: string, error: string}>}
     */
    public function refundEventOrders(Event $event, string $reason): array
    {
        $orderIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->join('sessions', 'sessions.id', '=', 'inventory_items.session_id')
            ->where('sessions.event_id', $event->id)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->distinct()
            ->pluck('orders.id');

        $refunded = 0;
        $failures = [];

        foreach ($orderIds as $orderId) {
            $order = Order::query()->find($orderId);

            if ($order === null) {
                continue;
            }

            $succeededPayments = $order->payments()
                ->where('status', PaymentStateMachine::SUCCEEDED)
                ->orderBy('id')
                ->get();

            if ($succeededPayments->isEmpty()) {
                continue;
            }

            // Полный проход через домен вместо прямого update: RefundService сам
            // считает остаток, ставит refund-строку, дерёт провайдера с
            // детерминированным ключом и через RefundLedger двигает статус заказа.
            try {
                foreach ($succeededPayments as $payment) {
                    $this->refunds->refund($payment, null, $reason);
                }

                $refunded++;
            } catch (\Throwable $e) {
                $failures[] = [
                    'order' => (string) ($order->public_id ?? $order->id),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'refunded' => $refunded,
            'refund_failures' => $failures,
        ];
    }

    /**
     * Каскад «возвраты → отмена события». После гонки возвратов обычная
     * политика должна пустить отмену — иначе возвращаем честный 409 с точным
     * остатком неразобранных продаж.
     */
    public function cancelEventWithRefunds(Event $event, \Nabilet\Modules\Events\Services\EventPublicationService $publication, string $reason): array
    {
        $outcome = $this->refundEventOrders($event, $reason);

        if ($outcome['refund_failures'] !== []) {
            // Часть денег не вернулась: отменять событие сейчас значило бы
            // оставить оплаченные билеты валидными. Оператору — ошибки, событие
            // остаётся как есть; повторный вызов доведёт невозращённые заказы.
            throw new DomainRuleViolation(
                'Not all paid orders could be refunded; event is not cancelled.',
                'REFUND_PARTIALLY_FAILED',
                ['event_id' => (string) $event->public_id] + $outcome,
                409,
            );
        }

        $publication->cancel($event);

        return $outcome;
    }
}
