<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Order lifecycle (ТЗ §26).
 *
 * Two independent axes are modelled here — payment progress and fulfillment — but
 * collapsed into one status because a customer only ever asks one question: "is my
 * order fine?". `payment_status` on the order row tracks the money separately for
 * reconciliation with the provider.
 *
 * Notable decisions:
 *  - `expired` is distinct from `canceled`. Canceled = a human decided; expired =
 *    the 10-minute payment window closed and holds were released (ТЗ §84). Support
 *    and analytics need that difference.
 *  - `failed` is NOT terminal: a customer whose card was declined must be able to
 *    retry with another card, so `failed → awaiting_payment` is legal.
 *  - `partially_refunded → refunded` is guarded: the order may only become fully
 *    `refunded` when the refunded amount actually equals the order total. Without
 *    that guard a partial refund of one seat out of four could mark the whole order
 *    refunded and release seats that were never returned.
 */
final class OrderStateMachine
{
    public const PENDING = 'pending';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const PAID = 'paid';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUNDED = 'refunded';
    public const CANCELED = 'canceled';
    public const EXPIRED = 'expired';
    public const FAILED = 'failed';

    public static function make(): StateMachine
    {
        $machine = StateMachine::define(
            name: 'Order',
            initial: self::PENDING,
            states: [
                self::PENDING,
                self::AWAITING_PAYMENT,
                self::PAID,
                self::PARTIALLY_REFUNDED,
                self::REFUNDED,
                self::CANCELED,
                self::EXPIRED,
                self::FAILED,
            ],
            transitions: [
                self::PENDING => [self::AWAITING_PAYMENT, self::CANCELED, self::EXPIRED, self::FAILED],
                self::AWAITING_PAYMENT => [self::PAID, self::CANCELED, self::EXPIRED, self::FAILED],
                self::PAID => [self::PARTIALLY_REFUNDED, self::REFUNDED],
                self::PARTIALLY_REFUNDED => [self::REFUNDED],
                self::REFUNDED => [],
                self::CANCELED => [],
                self::EXPIRED => [],
                // a declined card must not trap the customer
                self::FAILED => [self::AWAITING_PAYMENT, self::CANCELED, self::EXPIRED],
            ],
            terminal: [self::REFUNDED, self::CANCELED, self::EXPIRED],
        );

        // A partial refund may only complete the order when everything was returned.
        $machine->guard(self::PARTIALLY_REFUNDED, self::REFUNDED, static function (array $ctx): bool {
            $total = $ctx['total_minor'] ?? null;
            $refunded = $ctx['refunded_minor'] ?? null;

            return is_int($total) && is_int($refunded) && $total > 0 && $refunded >= $total;
        });

        // Direct paid -> refunded likewise requires a full refund.
        $machine->guard(self::PAID, self::REFUNDED, static function (array $ctx): bool {
            $total = $ctx['total_minor'] ?? null;
            $refunded = $ctx['refunded_minor'] ?? null;

            return is_int($total) && is_int($refunded) && $total > 0 && $refunded >= $total;
        });

        return $machine;
    }

    /** @return list<string> statuses in which the order still occupies seats */
    public static function occupyingInventory(): array
    {
        return [self::PENDING, self::AWAITING_PAYMENT, self::PAID, self::PARTIALLY_REFUNDED];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::REFUNDED, self::CANCELED, self::EXPIRED], true);
    }
}
