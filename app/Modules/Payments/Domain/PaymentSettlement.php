<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Domain;

use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;

/**
 * Decides what a payment callback may do to a payment and its order (ТЗ §27, §28).
 *
 * This is the piece that has to be right for "three webhooks arrive" and "one
 * webhook is forged" to end differently. The rules, in order:
 *
 *  1. A TERMINAL ORDER IS NEVER RESURRECTED. Money arriving for a cancelled or
 *     expired order cannot quietly revive it — the seats may have been resold in
 *     the meantime, and a paid order whose seats belong to someone else is the
 *     worst state this system can be in. It needs a human, so it is refused.
 *
 *  2. THE AMOUNT MUST MATCH EXACTLY, ON EVENTS THAT MOVE MONEY. A callback
 *     reporting less than the order total is not a partial payment that can be
 *     accepted — there is no such thing as half an order here. Reporting more is
 *     equally refused rather than silently banked. `canceled` and `failed` carry
 *     no money, so their amount is not checked.
 *
 *  3. ALREADY IN THE TARGET STATE IS SUCCESS, NOT ERROR. Providers retry. A
 *     duplicate `succeeded` must return the same 200 and change nothing — this is
 *     what makes the delivery idempotent even when the provider sends no
 *     idempotency key at all.
 *
 *  4. A TERMINAL PAYMENT CANNOT CHANGE ITS MIND. Once money has been taken, a
 *     later `canceled` callback cannot un-take it; that is a refund, which is a
 *     different object with its own lifecycle (`refunds`).
 *
 *  5. SIDE EFFECTS ARE COMPUTED, NOT PERFORMED. `issueTickets` is returned, not
 *     executed, and is suppressed when the tickets already exist — so the second
 *     delivery cannot issue a second set.
 */
final class PaymentSettlement
{
    public const REASON_ORDER_TERMINAL = 'order_terminal';
    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';
    public const REASON_CONFLICTING_TERMINAL = 'conflicting_terminal_payment';
    public const REASON_ILLEGAL_PAYMENT_TRANSITION = 'illegal_payment_transition';
    public const REASON_ILLEGAL_ORDER_TRANSITION = 'illegal_order_transition';

    public function settle(SettlementRequest $request): SettlementDecision
    {
        $payments = PaymentStateMachine::make();
        $orders = OrderStateMachine::make();

        // 1. never resurrect a finished order
        if ($orders->isTerminal($request->orderStatus)) {
            return SettlementDecision::refused(self::REASON_ORDER_TERMINAL);
        }

        $target = $request->providerEvent;

        // 2. money that moves must move in the right amount
        $movesMoney = in_array(
            $target,
            [PaymentStateMachine::SUCCEEDED, PaymentStateMachine::WAITING_FOR_CAPTURE],
            true
        );

        if ($movesMoney && ! $request->amountMatches()) {
            return SettlementDecision::refused(self::REASON_AMOUNT_MISMATCH);
        }

        // 3. duplicate delivery — the answer providers need to hear is "ok"
        if ($request->paymentStatus === $target) {
            return SettlementDecision::replay();
        }

        // 4. a finished payment does not change its mind
        if ($payments->isTerminal($request->paymentStatus)) {
            return SettlementDecision::refused(self::REASON_CONFLICTING_TERMINAL);
        }

        if (! $payments->can($request->paymentStatus, $target)) {
            return SettlementDecision::refused(self::REASON_ILLEGAL_PAYMENT_TRANSITION);
        }

        $desiredOrderStatus = $this->orderStatusFor($target, $request->orderStatus);

        if ($desiredOrderStatus !== null && $desiredOrderStatus !== $request->orderStatus) {
            if (! $orders->can($request->orderStatus, $desiredOrderStatus)) {
                return SettlementDecision::refused(self::REASON_ILLEGAL_ORDER_TRANSITION);
            }
        } else {
            $desiredOrderStatus = null;
        }

        $issueTickets = $target === PaymentStateMachine::SUCCEEDED
            && ! $request->ticketsAlreadyIssued;

        $releaseHolds = in_array(
            $desiredOrderStatus,
            [OrderStateMachine::CANCELLED, OrderStateMachine::EXPIRED],
            true
        );

        return SettlementDecision::apply($target, $desiredOrderStatus, $issueTickets, $releaseHolds);
    }

    /**
     * Which order status a payment outcome implies, or null for "the order does
     * not move".
     *
     * `null` is a real answer, not a gap: an authorization hold
     * (`waiting_for_capture`) on an order that is already awaiting payment leaves
     * the order exactly where it is, and re-stating the same status would be a
     * pointless write and a misleading audit row.
     */
    private function orderStatusFor(string $paymentEvent, string $currentOrderStatus): ?string
    {
        return match ($paymentEvent) {
            PaymentStateMachine::SUCCEEDED => OrderStateMachine::PAID,
            PaymentStateMachine::CANCELED => OrderStateMachine::CANCELLED,
            PaymentStateMachine::FAILED => OrderStateMachine::PAYMENT_FAILED,
            PaymentStateMachine::WAITING_FOR_CAPTURE =>
                $currentOrderStatus === OrderStateMachine::AWAITING_PAYMENT
                    ? null
                    : OrderStateMachine::AWAITING_PAYMENT,
            // A pending callback carries no new information about the order.
            default => null,
        };
    }
}
