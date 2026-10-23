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
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    /**
     * The schema spells this `payment_failed` (ck_orders_status), not `failed` —
     * because on an order, "failed" is ambiguous: the order did not fail, the
     * PAYMENT did. Keeping the constant name FAILED with the value 'failed' is
     * exactly how this drifted out of sync with the database in the first place,
     * so the constant is named after what it actually means.
     */
    public const PAYMENT_FAILED = 'payment_failed';

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
                self::CANCELLED,
                self::EXPIRED,
                self::PAYMENT_FAILED,
            ],
            transitions: [
                self::PENDING => [self::AWAITING_PAYMENT, self::CANCELLED, self::EXPIRED, self::PAYMENT_FAILED],
                self::AWAITING_PAYMENT => [self::PAID, self::CANCELLED, self::EXPIRED, self::PAYMENT_FAILED],
                self::PAID => [self::PARTIALLY_REFUNDED, self::REFUNDED],
                self::PARTIALLY_REFUNDED => [self::REFUNDED],
                self::REFUNDED => [],
                self::CANCELLED => [],
                self::EXPIRED => [],
                // a declined card must not trap the customer
                self::PAYMENT_FAILED => [self::AWAITING_PAYMENT, self::CANCELLED, self::EXPIRED],
            ],
            terminal: [self::REFUNDED, self::CANCELLED, self::EXPIRED],
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

    /**
     * Statuses in which the order still holds the customer's money.
     *
     * TWO PLACES ASK THE SAME QUESTION — «does this event have unsettled
     * obligations toward its customers» — and they must give the same answer:
     * how many units are sold (`EventPublicationService::soldUnits()`, which
     * decides whether the event may be cancelled at all) and which orders to
     * refund (`EventOrderRefunder::refundEventOrders()`). When one counts an
     * order as an obligation and the other does not, the event becomes
     * impossible to cancel: there are sold units, but nothing to refund.
     *
     * `cancelled` and `refunded` are absent — the money is back, or was never
     * taken. `pending`, `awaiting_payment` and `payment_failed` are absent for
     * the same reason: no payment happened, so there is nothing to return.
     *
     * WHAT THIS REPLACES. Both call sites filtered `NOT IN ('cancelled',
     * 'refunded')`, which treats an unpaid order as an obligation. A cart
     * abandoned at minute ten and then moved to `expired` by
     * `StaleOrderExpirer` therefore locked event cancellation forever — and
     * locked `cancelEventWithRefunds()` too, because after refunding it still
     * asks the policy, and the policy still sees «sold». The refusal message
     * («refunds must be settled first») then points at refunds that do not
     * exist. Note the same file already had `occupyingInventory()` excluding
     * `expired`: the seats were back on sale while the order still counted as
     * sold.
     *
     * @return list<string>
     */
    public static function refundableStatuses(): array
    {
        return [self::PAID, self::PARTIALLY_REFUNDED];
    }

    /**
     * Statuses in which money was taken, whether or not it was later returned.
     *
     * For «has this customer bought from us before» (`PromoCodeService`), a
     * refunded order still counts: the purchase happened. This set also removed
     * a literal that no longer exists — the caller listed `completed`, which
     * `ck_orders_status` has never allowed (see the machine's states above).
     *
     * @return list<string>
     */
    public static function paidStatuses(): array
    {
        return [self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED];
    }

    /**
     * Statuses that can still change: no payment and no cancellation yet.
     *
     * `StaleOrderExpirer` walks exactly these to find orders whose payment
     * window has closed.
     *
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [self::PENDING, self::AWAITING_PAYMENT, self::PAYMENT_FAILED];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::REFUNDED, self::CANCELLED, self::EXPIRED], true);
    }
}
