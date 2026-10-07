<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Support;

use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Имя события заказа — `order.<status>`.
 *
 * Одно имя обслуживает два потребителя: код шаблона письма и тип исходящего
 * вебхука. Это осознанно: партнёр, подписавшийся на `order.paid`, и письмо с
 * кодом `order.paid` говорят об одном и том же событии, и пусть их имена
 * совпадают, чем расходятся и порождают две таблицы соответствий.
 *
 * `pending` события не порождает: заказ ещё не подтверждён покупателем.
 * Список ниже соответствует `ck_orders_status` из Core-схемы.
 */
final class OrderEventName
{
    public const PREFIX = 'order.';

    /** @var list<string> */
    public const STATUSES_WITH_EVENTS = [
        OrderStateMachine::AWAITING_PAYMENT,
        OrderStateMachine::PAID,
        OrderStateMachine::CANCELLED,
        OrderStateMachine::REFUNDED,
        OrderStateMachine::PARTIALLY_REFUNDED,
        OrderStateMachine::PAYMENT_FAILED,
        OrderStateMachine::EXPIRED,
    ];

    /** Имя события для статуса или null, если события по нему нет. */
    public static function forStatus(string $status): ?string
    {
        return in_array($status, self::STATUSES_WITH_EVENTS, true)
            ? self::PREFIX . $status
            : null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(
            static fn (string $status): string => self::PREFIX . $status,
            self::STATUSES_WITH_EVENTS,
        );
    }
}
