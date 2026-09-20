<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Domain\PaymentSettlement;
use Nabilet\Modules\Payments\Domain\RefundLedger;
use Nabilet\Modules\Payments\Domain\SettlementRequest;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * What a provider callback is allowed to do — and what it must never do twice.
 *
 * The webhook path is the least trustworthy input in the system: it arrives out of
 * order, it arrives more than once, and its payload is signed by somebody else's
 * infrastructure. Everything below is about making the order's state a function of
 * rules rather than of arrival order.
 *
 * ТЗ §28 sets the bar directly: three deliveries of the same webhook must produce
 * one paid order and one set of tickets.
 */
final class PaymentSettlementTest extends TestCase
{
    private const TOTAL = 125000;

    private function request(
        string $providerEvent,
        string $orderStatus = OrderStateMachine::AWAITING_PAYMENT,
        string $paymentStatus = PaymentStateMachine::PENDING,
        ?int $reportedAmount = null,
        bool $ticketsAlreadyIssued = false,
    ): SettlementRequest {
        return new SettlementRequest(
            orderStatus: $orderStatus,
            paymentStatus: $paymentStatus,
            orderTotal: Money::of(self::TOTAL),
            reportedAmount: Money::of($reportedAmount ?? self::TOTAL),
            providerEvent: $providerEvent,
            ticketsAlreadyIssued: $ticketsAlreadyIssued,
        );
    }

    // ── the happy path ───────────────────────────────────────────────────────

    public function testASuccessfulPaymentPaysTheOrderAndIssuesTickets(): void
    {
        $decision = (new PaymentSettlement())->settle($this->request(PaymentStateMachine::SUCCEEDED));

        $this->assertTrue($decision->isApplied());
        $this->assertSame(PaymentStateMachine::SUCCEEDED, $decision->paymentStatus());
        $this->assertSame(OrderStateMachine::PAID, $decision->orderStatus());
        $this->assertTrue($decision->issueTickets());
        $this->assertFalse($decision->releaseHolds());
    }

    /** ТЗ §28: three deliveries, one paid order, one ticket issuance. */
    public function testADuplicateSuccessCallbackIsAReplay(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, paymentStatus: PaymentStateMachine::SUCCEEDED)
        );

        $this->assertTrue($decision->isReplay());
        $this->assertFalse($decision->isApplied());
        $this->assertNull($decision->paymentStatus());
        $this->assertFalse($decision->issueTickets(), 'a replay must never issue a second set');
    }

    /** The tickets exist but the payment row does not say so yet. */
    public function testTicketsAreNotIssuedTwiceEvenWhenThePaymentStillMoves(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, ticketsAlreadyIssued: true)
        );

        $this->assertTrue($decision->isApplied());
        $this->assertSame(OrderStateMachine::PAID, $decision->orderStatus());
        $this->assertFalse($decision->issueTickets());
    }

    // ── a finished order is not resurrected ──────────────────────────────────

    public function testMoneyArrivingForACancelledOrderIsRefused(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, OrderStateMachine::CANCELLED)
        );

        $this->assertTrue($decision->isRefused());
        $this->assertSame(PaymentSettlement::REASON_ORDER_TERMINAL, $decision->reason());
    }

    public function testMoneyArrivingForAnExpiredOrderIsRefused(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, OrderStateMachine::EXPIRED)
        );

        $this->assertSame(PaymentSettlement::REASON_ORDER_TERMINAL, $decision->reason());
    }

    /** The seats may have been resold; a paid order with someone else's seats is
     *  the worst state this system can reach, so it must reach a human instead. */
    public function testARefundedOrderIsNotResurrectedEither(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, OrderStateMachine::REFUNDED)
        );

        $this->assertSame(PaymentSettlement::REASON_ORDER_TERMINAL, $decision->reason());
    }

    // ── the amount must be right ─────────────────────────────────────────────

    public function testAnUnderpaymentIsRefused(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, reportedAmount: self::TOTAL - 100)
        );

        $this->assertTrue($decision->isRefused());
        $this->assertSame(PaymentSettlement::REASON_AMOUNT_MISMATCH, $decision->reason());
    }

    public function testAnOverpaymentIsRefusedRatherThanSilentlyBanked(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, reportedAmount: self::TOTAL + 100)
        );

        $this->assertSame(PaymentSettlement::REASON_AMOUNT_MISMATCH, $decision->reason());
    }

    /** An authorization hold for the wrong amount is wrong too. */
    public function testAWaitingForCaptureForTheWrongAmountIsRefused(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::WAITING_FOR_CAPTURE, reportedAmount: self::TOTAL - 1)
        );

        $this->assertSame(PaymentSettlement::REASON_AMOUNT_MISMATCH, $decision->reason());
    }

    /** No money moved, so the reported amount is irrelevant. */
    public function testACancellationIsJudgedWithoutRegardToAmount(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::CANCELED, reportedAmount: 0)
        );

        $this->assertTrue($decision->isApplied());
        $this->assertSame(OrderStateMachine::CANCELLED, $decision->orderStatus());
    }

    // ── a finished payment does not change its mind ──────────────────────────

    public function testACancellationAfterSuccessIsRefused(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::CANCELED, paymentStatus: PaymentStateMachine::SUCCEEDED)
        );

        $this->assertTrue($decision->isRefused());
        $this->assertSame(PaymentSettlement::REASON_CONFLICTING_TERMINAL, $decision->reason());
    }

    /** Money already taken can only come back as a refund, which is its own object. */
    public function testASuccessAfterFailureIsRefusedRatherThanSwapped(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::SUCCEEDED, paymentStatus: PaymentStateMachine::FAILED)
        );

        $this->assertSame(PaymentSettlement::REASON_CONFLICTING_TERMINAL, $decision->reason());
    }

    // ── the other outcomes ───────────────────────────────────────────────────

    public function testAFailureMovesTheOrderToPaymentFailed(): void
    {
        $decision = (new PaymentSettlement())->settle($this->request(PaymentStateMachine::FAILED));

        $this->assertTrue($decision->isApplied());
        $this->assertSame(OrderStateMachine::PAYMENT_FAILED, $decision->orderStatus());
        $this->assertFalse($decision->issueTickets());
        $this->assertFalse($decision->releaseHolds(), 'a declined card may be retried');
    }

    public function testACancellationReleasesTheHolds(): void
    {
        $decision = (new PaymentSettlement())->settle($this->request(PaymentStateMachine::CANCELED));

        $this->assertSame(OrderStateMachine::CANCELLED, $decision->orderStatus());
        $this->assertTrue($decision->releaseHolds());
    }

    public function testAnAuthorizationHoldMovesTheOrderToAwaitingPayment(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::WAITING_FOR_CAPTURE, OrderStateMachine::PENDING)
        );

        $this->assertSame(OrderStateMachine::AWAITING_PAYMENT, $decision->orderStatus());
        $this->assertFalse($decision->issueTickets());
    }

    /** Re-stating the same status is a pointless write and a misleading audit row. */
    public function testAnAuthorizationHoldOnAnAlreadyAwaitingOrderChangesNoOrderStatus(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::WAITING_FOR_CAPTURE, OrderStateMachine::AWAITING_PAYMENT)
        );

        $this->assertTrue($decision->isApplied());
        $this->assertSame(PaymentStateMachine::WAITING_FOR_CAPTURE, $decision->paymentStatus());
        $this->assertNull($decision->orderStatus());
    }

    public function testAPendingCallbackCarriesNoNewsForTheOrder(): void
    {
        $decision = (new PaymentSettlement())->settle(
            $this->request(PaymentStateMachine::PENDING, paymentStatus: PaymentStateMachine::PENDING)
        );

        $this->assertTrue($decision->isReplay());
    }

    // ── input invariants ─────────────────────────────────────────────────────

    public function testACallbackInAnotherCurrencyIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new SettlementRequest(
                OrderStateMachine::AWAITING_PAYMENT,
                PaymentStateMachine::PENDING,
                Money::of(self::TOTAL, 'RUB'),
                Money::of(self::TOTAL, 'EUR'),
                PaymentStateMachine::SUCCEEDED,
            )
        );
    }

    /** The provider's own spelling must be translated before it reaches here. */
    public function testAnUnknownProviderEventIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new SettlementRequest(
                OrderStateMachine::AWAITING_PAYMENT,
                PaymentStateMachine::PENDING,
                Money::of(self::TOTAL),
                Money::of(self::TOTAL),
                'waiting_for_confirmation',
            )
        );
    }

    // ── refunds ──────────────────────────────────────────────────────────────

    public function testAPartialRefundLeavesTheOrderPartiallyRefunded(): void
    {
        $outcome = (new RefundLedger())->evaluate(
            Money::of(400000),
            Money::zero(),
            Money::of(100000),
        );

        $this->assertTrue($outcome->isAllowed());
        $this->assertSame(OrderStateMachine::PARTIALLY_REFUNDED, $outcome->orderStatus());
        $this->assertSame(100000, $outcome->refundedTotal()->minorUnits());
        $this->assertSame(300000, $outcome->remaining()->minorUnits());
        $this->assertFalse($outcome->isFullRefund());
    }

    /** The status is arithmetic, not a decision: only zero remaining means refunded. */
    public function testTheLastRefundCompletesTheOrder(): void
    {
        $outcome = (new RefundLedger())->evaluate(
            Money::of(400000),
            Money::of(300000),
            Money::of(100000),
        );

        $this->assertTrue($outcome->isAllowed());
        $this->assertSame(OrderStateMachine::REFUNDED, $outcome->orderStatus());
        $this->assertSame(0, $outcome->remaining()->minorUnits());
        $this->assertTrue($outcome->isFullRefund());
    }

    public function testTwoOperatorsCannotRefundTheSameMoneyTwice(): void
    {
        $ledger = new RefundLedger();

        $first = $ledger->evaluate(Money::of(400000), Money::zero(), Money::of(400000));
        $this->assertTrue($first->isAllowed());

        $second = $ledger->evaluate(Money::of(400000), Money::of(400000), Money::of(400000));

        $this->assertFalse($second->isAllowed());
        $this->assertSame(RefundLedger::REASON_EXCEEDS_PAID, $second->reason());
    }

    public function testARefundThatOvershootsByOneKopeckIsRefused(): void
    {
        $outcome = (new RefundLedger())->evaluate(
            Money::of(400000),
            Money::of(100000),
            Money::of(300001),
        );

        $this->assertFalse($outcome->isAllowed());
        $this->assertSame(RefundLedger::REASON_EXCEEDS_PAID, $outcome->reason());
    }

    public function testNothingCanBeRefundedOnAnUnpaidOrder(): void
    {
        $outcome = (new RefundLedger())->evaluate(
            Money::zero(),
            Money::zero(),
            Money::of(100000),
        );

        $this->assertFalse($outcome->isAllowed());
        $this->assertSame(RefundLedger::REASON_NOTHING_PAID, $outcome->reason());
    }

    public function testAZeroRefundIsRefused(): void
    {
        $outcome = (new RefundLedger())->evaluate(
            Money::of(400000),
            Money::zero(),
            Money::zero(),
        );

        $this->assertFalse($outcome->isAllowed());
        $this->assertSame(RefundLedger::REASON_ZERO_AMOUNT, $outcome->reason());
    }

    public function testRefundInFullReturnsExactlyWhatIsLeft(): void
    {
        $outcome = (new RefundLedger())->refundInFull(Money::of(400000), Money::of(150000));

        $this->assertTrue($outcome->isAllowed());
        $this->assertSame(400000, $outcome->refundedTotal()->minorUnits());
        $this->assertSame(OrderStateMachine::REFUNDED, $outcome->orderStatus());
    }

    public function testRefundAmountsMustShareOneCurrency(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => (new RefundLedger())->evaluate(
                Money::of(400000, 'RUB'),
                Money::zero('RUB'),
                Money::of(1000, 'USD'),
            )
        );
    }
}
