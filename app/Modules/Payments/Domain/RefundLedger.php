<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * How much of a payment has come back, and what that makes the order (ТЗ §85).
 *
 * The order status is DERIVED from money, never assigned by a caller. That is the
 * whole point of this class: `partially_refunded` and `refunded` look like a
 * decision someone makes, but they are arithmetic. Guessing them is how an order
 * gets marked `refunded` after one seat out of four was returned — and
 * `OrderStateMachine` guards that transition on `refunded >= total` precisely
 * because it has to be true, not merely asserted.
 *
 * Two invariants, both cheap to state and expensive to violate:
 *
 *   NEVER REFUND MORE THAN WAS PAID. `refunds.amount` has no CHECK tying it to
 *   `payments.amount`, so nothing in the schema stops it. Two operators issuing a
 *   full refund each, or a retried request, would pay the customer twice and put
 *   the organizer in the hole.
 *
 *   NOTHING TO REFUND ON AN UNPAID ORDER. Refunding a `pending` or
 *   `payment_failed` order would create a `refunds` row against money that was
 *   never captured; the provider rejects it, and the local row then says
 *   something that never happened.
 */
final class RefundLedger
{
    public const REASON_NOTHING_PAID = 'nothing_paid';
    public const REASON_EXCEEDS_PAID = 'exceeds_paid';
    public const REASON_ZERO_AMOUNT = 'zero_amount';

    /**
     * @param Money $paid            what the provider actually captured
     * @param Money $alreadyRefunded succeeded refunds so far, excluding this one
     * @param Money $amount          this refund
     */
    public function evaluate(Money $paid, Money $alreadyRefunded, Money $amount): RefundOutcome
    {
        $this->assertSameCurrency($paid, $alreadyRefunded, $amount);

        $currency = $paid->currency();
        $returned = $alreadyRefunded->minorUnits();
        $paidMinor = $paid->minorUnits();

        if (! $paid->isPositive()) {
            return RefundOutcome::refused(
                self::REASON_NOTHING_PAID,
                Money::of($returned, $currency),
                Money::zero($currency)
            );
        }

        if ($amount->minorUnits() < 1) {
            return RefundOutcome::refused(
                self::REASON_ZERO_AMOUNT,
                Money::of($returned, $currency),
                Money::of(max(0, $paidMinor - $returned), $currency)
            );
        }

        if ($returned + $amount->minorUnits() > $paidMinor) {
            return RefundOutcome::refused(
                self::REASON_EXCEEDS_PAID,
                Money::of($returned, $currency),
                Money::of(max(0, $paidMinor - $returned), $currency)
            );
        }

        $newReturned = $returned + $amount->minorUnits();
        $remaining = $paidMinor - $newReturned;

        // The order only becomes fully `refunded` when every kopeck is back.
        $orderStatus = $remaining === 0
            ? OrderStateMachine::REFUNDED
            : OrderStateMachine::PARTIALLY_REFUNDED;

        return RefundOutcome::allowed(
            $orderStatus,
            Money::of($newReturned, $currency),
            Money::of($remaining, $currency)
        );
    }

    /** Convenience for the common case: give everything back. */
    public function refundInFull(Money $paid, Money $alreadyRefunded): RefundOutcome
    {
        return $this->evaluate(
            $paid,
            $alreadyRefunded,
            Money::of(max(0, $paid->minorUnits() - $alreadyRefunded->minorUnits()), $paid->currency())
        );
    }

    private function assertSameCurrency(Money ...$amounts): void
    {
        $currency = $amounts[0]->currency();

        foreach ($amounts as $amount) {
            if ($amount->currency() !== $currency) {
                throw new DomainRuleViolation(
                    sprintf(
                        'Refund amounts must share one currency, got %s and %s.',
                        $currency,
                        $amount->currency()
                    ),
                    'CURRENCY_MISMATCH'
                );
            }
        }
    }
}
