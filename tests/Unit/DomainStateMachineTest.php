<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Modules\Inventory\StateMachines\HoldStateMachine;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * These tests encode the commercial rules from the specification. Each one
 * corresponds to a scenario in the ТЗ that would cost real money or real trust if
 * it broke — they are the executable form of the acceptance criteria.
 */
final class DomainStateMachineTest extends TestCase
{
    // ------------------------------------------------------------------ Order

    public function testOrderHappyPath(): void
    {
        $m = OrderStateMachine::make();

        $m->assert('pending', 'awaiting_payment');
        $m->assert('awaiting_payment', 'paid');
        $this->assertTrue(true);
    }

    /**
     * ТЗ §84: the 10-minute window closes, holds are released, and the order must
     * be distinguishable from a human cancellation.
     */
    public function testExpiredOrderIsDistinctFromCanceled(): void
    {
        $m = OrderStateMachine::make();

        $m->assert('awaiting_payment', 'expired');
        $m->assert('awaiting_payment', 'cancelled');

        $this->assertTrue($m->isTerminal('expired'));
        $this->assertTrue($m->isTerminal('cancelled'));
        $this->assertFalse($m->can('expired', 'paid'));
        $this->assertFalse($m->can('cancelled', 'paid'));
    }

    /**
     * A declined card must not trap the customer: payment_failed ->
     * awaiting_payment is legal so they can retry with another card.
     *
     * The status is `payment_failed`, not `failed` — on an order, the PAYMENT
     * failed, not the order. ck_orders_status spells it that way and
     * OrderStateMachine::PAYMENT_FAILED is the constant.
     */
    public function testFailedPaymentAllowsRetry(): void
    {
        $m = OrderStateMachine::make();

        $m->assert('awaiting_payment', 'payment_failed');
        $m->assert('payment_failed', 'awaiting_payment');
        $m->assert('awaiting_payment', 'paid');
        $this->assertTrue(true);
    }

    public function testPaidOrderCannotBeCanceledOrExpired(): void
    {
        $m = OrderStateMachine::make();

        $this->assertFalse($m->can('paid', 'cancelled'), 'money was taken; only a refund path is legal');
        $this->assertFalse($m->can('paid', 'expired'));
    }

    /**
     * The guard that protects refunds: one refunded seat out of four must not mark
     * the whole order refunded and release the other three seats.
     */
    public function testPartialRefundCannotCompleteTheOrder(): void
    {
        $m = OrderStateMachine::make();

        $this->assertThrows(
            InvalidStateTransitionError::class,
            static fn () => $m->assert('partially_refunded', 'refunded', [
                'total_minor' => 20000,
                'refunded_minor' => 5000,
            ])
        );
    }

    public function testFullRefundCompletesTheOrder(): void
    {
        $m = OrderStateMachine::make();

        $m->assert('partially_refunded', 'refunded', [
            'total_minor' => 20000,
            'refunded_minor' => 20000,
        ]);
        $this->assertTrue(true);
    }

    public function testDirectFullRefundFromPaidIsAllowed(): void
    {
        $m = OrderStateMachine::make();

        $m->assert('paid', 'refunded', ['total_minor' => 5300, 'refunded_minor' => 5300]);
        $this->assertTrue(true);
    }

    public function testRefundedOrderIsTerminal(): void
    {
        $m = OrderStateMachine::make();

        $this->assertTrue($m->isTerminal('refunded'));
        $this->assertFalse($m->can('refunded', 'paid'));
    }

    public function testOccupyingInventoryStatuses(): void
    {
        $this->assertSame(
            ['pending', 'awaiting_payment', 'paid', 'partially_refunded'],
            OrderStateMachine::occupyingInventory()
        );
    }

    // ---------------------------------------------------------------- Payment

    public function testTwoStepPaymentFlow(): void
    {
        $m = PaymentStateMachine::make();

        $m->assert('pending', 'waiting_for_capture');
        $m->assert('waiting_for_capture', 'succeeded');
        $this->assertTrue(true);
    }

    /**
     * ТЗ §28: three identical webhooks must not produce three paid orders. The
     * machine refuses succeeded -> succeeded, so the handler is forced to treat
     * "already in target state" as success rather than performing the work again.
     */
    public function testRepeatedWebhookTransitionIsRefusedSoHandlerMustBeIdempotent(): void
    {
        $m = PaymentStateMachine::make();

        $this->assertFalse(
            $m->can('succeeded', 'succeeded'),
            'self-transition must not be legal, otherwise a replayed webhook re-runs side effects'
        );
    }

    public function testPaymentCannotGoFromSucceededBackToPending(): void
    {
        $m = PaymentStateMachine::make();

        $this->assertFalse($m->can('succeeded', 'pending'));
        $this->assertFalse($m->can('succeeded', 'canceled'));
    }

    /**
     * A succeeded payment is terminal — there is no `refunded` state on a payment.
     *
     * ck_payments_status allows exactly five values and refunds are not among
     * them. Refund progress is its own row in `refunds` with its own machine
     * (requested → processing → succeeded/failed), which is the right shape: one
     * payment can carry several partial refunds, each with its own outcome, and
     * none of that fits in a single column.
     *
     * This test exists because the machine previously claimed a `refunded` state
     * the database would have rejected — an invented state that only surfaces as a
     * constraint violation the first time someone refunds in production.
     */
    public function testSucceededPaymentIsTerminalAndHasNoRefundState(): void
    {
        $m = PaymentStateMachine::make();

        $this->assertTrue($m->isTerminal('succeeded'));
        $this->assertFalse($m->can('succeeded', 'refunded'));
        $this->assertFalse($m->can('succeeded', 'partially_refunded'));
        $this->assertFalse($m->isKnownState('refunded'));
        $this->assertFalse($m->isKnownState('partially_refunded'));
    }

    public function testMoneyReceivedStatuses(): void
    {
        $this->assertSame(['succeeded'], PaymentStateMachine::moneyReceived());
    }

    // ----------------------------------------------------------------- Refund

    public function testRefundCanBeRetriedAfterTransientFailure(): void
    {
        $m = RefundStateMachine::make();

        $m->assert('requested', 'processing');
        $m->assert('processing', 'failed');
        $m->assert('failed', 'processing');
        $m->assert('processing', 'succeeded');
        $this->assertTrue(true);
    }

    public function testSucceededRefundIsTerminal(): void
    {
        $m = RefundStateMachine::make();

        $this->assertTrue($m->isTerminal('succeeded'));
        $this->assertFalse($m->can('succeeded', 'processing'));
    }

    // ----------------------------------------------------------------- Ticket

    public function testTicketCheckInFlow(): void
    {
        $m = TicketStateMachine::make();

        $m->assert('issued', 'used');
        $this->assertTrue($m->isTerminal('used') === false, 'used is not terminal: it can still be revoked');
    }

    /**
     * ТЗ §32: the second scan must report "already used". There is deliberately no
     * way back to `issued`, so a support mistake cannot enable double entry.
     */
    public function testUsedTicketCannotBeReturnedToIssued(): void
    {
        $m = TicketStateMachine::make();

        $this->assertFalse($m->can('used', 'issued'));
        $this->assertSame(['revoked'], $m->allowedFrom('used'));
    }

    public function testUsedTicketCanBeRevokedForFraud(): void
    {
        $m = TicketStateMachine::make();

        $m->assert('used', 'revoked');
        $this->assertTrue(true);
    }

    public function testRefundedTicketIsTerminal(): void
    {
        $m = TicketStateMachine::make();

        $this->assertTrue($m->isTerminal('refunded'));
        $this->assertFalse($m->can('refunded', 'issued'));
    }

    public function testAdmissibleTicketsAtTheDoor(): void
    {
        $this->assertSame(['issued'], TicketStateMachine::admissible());
    }

    // -------------------------------------------------------------- Inventory

    /**
     * ТЗ §24: two buyers, one seat. Only one hold may win, and the loser must see
     * the seat as unavailable rather than being able to hold it again.
     */
    public function testHeldSeatCannotBeHeldAgain(): void
    {
        $m = InventoryItemStateMachine::make();

        $m->assert('available', 'held');
        $this->assertFalse(
            $m->can('held', 'held'),
            'a second hold on the same seat must be refused'
        );
    }

    public function testHoldExpiryReturnsSeatToAvailable(): void
    {
        $m = InventoryItemStateMachine::make();

        $m->assert('held', 'available');
        $this->assertTrue(true);
    }

    public function testHoldConvertsToSoldOnPayment(): void
    {
        $m = InventoryItemStateMachine::make();

        $m->assert('held', 'sold');
        $this->assertTrue(true);
    }

    public function testRefundReleasesSeatBackToAvailable(): void
    {
        $m = InventoryItemStateMachine::make();

        $m->assert('sold', 'available');
        $this->assertTrue(true);
    }

    public function testPurchasableIsOnlyAvailable(): void
    {
        $this->assertSame(['available'], InventoryItemStateMachine::purchasable());
    }

    /**
     * `sold_out` — вместимость исчерпана, и это НЕ `sold`.
     *
     * ДЕФЕКТ, который эти три теста фиксируют. Состояния `sold_out` в машине не
     * было, а `CartItemService::addItem()` пишет его с первого же добавления
     * МЕСТА в корзину: у места `capacity = 1`, поэтому `available_quantity`
     * обнуляется сразу и ветка `held` не достигается вовсе (`held` остаётся
     * только для стоячих зон, где ещё есть свободная вместимость).
     *
     * `PaymentService::markInventorySoldForOrder()` охраняет запись `→ sold`
     * вызовом `can()` (аудит R-12), поэтому `can('sold_out', 'sold')` == false
     * означал: ОПЛАЧЕННАЯ позиция навсегда остаётся `sold_out` — её нет ни в
     * отчётности по `sold`, а `OrderService` сбрасывает `sold_out` в `available`
     * при отмене заказа так же охотно, как `held`.
     *
     * Воспроизведение на реальном пути (корзина → checkout → оплата) —
     * `tests/Feature/Inventory/InventorySoldOnPaymentTest.php`.
     */
    public function testSoldOutIsCapacityExhaustedAndDistinctFromSold(): void
    {
        $m = InventoryItemStateMachine::make();

        $this->assertTrue(
            $m->isKnownState(InventoryItemStateMachine::SOLD_OUT),
            'sold_out отсутствует в словаре машины'
        );
        $this->assertNotSame(
            InventoryItemStateMachine::SOLD_OUT,
            InventoryItemStateMachine::SOLD,
            'исчерпанная вместимость и продажа — разные состояния'
        );
    }

    /** Исчерпание вместимости и её возврат — оба перехода легальны. */
    public function testCapacityExhaustionAndReturnAreBothAllowed(): void
    {
        $m = InventoryItemStateMachine::make();

        $m->assert('available', 'sold_out');
        $m->assert('held', 'sold_out');
        $m->assert('sold_out', 'available');
        $m->assert('sold_out', 'held');
    }

    /** Главный переход исправления: оплата доводит исчерпанную позицию до `sold`. */
    public function testSoldOutConvertsToSoldOnPayment(): void
    {
        InventoryItemStateMachine::make()->assert('sold_out', 'sold');
    }

    // ------------------------------------------------------------------- Hold

    public function testHoldTerminalStatesAreDistinct(): void
    {
        $m = HoldStateMachine::make();

        foreach (['converted', 'expired', 'released'] as $terminal) {
            $this->assertTrue($m->isTerminal($terminal));
        }
    }

    public function testExpiredHoldCannotBeConverted(): void
    {
        $m = HoldStateMachine::make();

        $this->assertFalse(
            $m->can('expired', 'converted'),
            'an expired hold must never be converted into an order'
        );
    }

    // ------------------------------------------------------------------- Event

    public function testEventPublishingAndArchiving(): void
    {
        $m = EventStateMachine::make();

        $m->assert('draft', 'published');
        $m->assert('published', 'completed');
        $m->assert('completed', 'archived');
        $this->assertTrue($m->isTerminal('archived'));
    }

    public function testArchivedEventCannotBeRepublished(): void
    {
        $m = EventStateMachine::make();

        $this->assertFalse($m->can('archived', 'published'));
    }

    public function testPubliclyVisibleStatesMatchSitemapRules(): void
    {
        // ТЗ §38: drafts must never reach the sitemap
        $this->assertNotContains('draft', EventStateMachine::publiclyVisible());
        $this->assertContains('published', EventStateMachine::publiclyVisible());
    }

    /**
     * Словарь машины, `EventStatus::all()` и CHECK `ck_events_status` — один
     * список.
     *
     * Машина и класс статусов существовали согласованно, а схема молчала:
     * `events.status` не имел CHECK, и `verify-state-machines.php` на каждом
     * прогоне писал «machine is unenforced». Теперь список закреплён в сплите
     * `015_events_status_check.sql`; этот тест держит три копии вместе, чтобы
     * добавление седьмого состояния не прошло незамеченным.
     */
    public function testEventVocabularyMatchesTheStatusCodeAndTheSchema(): void
    {
        $expected = [
            EventStateMachine::DRAFT,
            EventStateMachine::SCHEDULED,
            EventStateMachine::PUBLISHED,
            EventStateMachine::COMPLETED,
            EventStateMachine::CANCELLED,
            EventStateMachine::ARCHIVED,
        ];

        $this->assertSame($expected, EventStatus::all());

        // `states()` не обязан совпадать порядком — сравниваем как множества.
        // (`assertEqualsCanonicalizing` недоступен: `tests/run.php` работает на
        // собственном минимальном TestCase, а не на PHPUnit.)
        $states = EventStateMachine::make()->states();
        sort($states);

        $sortedExpected = $expected;
        sort($sortedExpected);

        $this->assertSame($sortedExpected, $states);
    }

    // ----------------------------------------------------------------- Session

    /**
     * `closed` (selling stopped) and `completed` (the performance happened) are
     * two different states, and the schema keeps them apart. A session whose box
     * office has shut has NOT yet happened — conflating the two would make "can I
     * still sell?" and "did this happen?" the same question.
     */
    public function testSessionClosesThenCompletes(): void
    {
        $m = SessionStateMachine::make();

        $m->assert('draft', 'scheduled');
        $m->assert('scheduled', 'on_sale');
        $m->assert('on_sale', 'closed');
        $m->assert('closed', 'completed');

        $this->assertFalse($m->isTerminal('closed'), 'sales are shut but the event has not happened');
        $this->assertTrue($m->isTerminal('completed'));
    }

    public function testSoldOutSessionCanReopenAfterRefund(): void
    {
        $m = SessionStateMachine::make();

        $m->assert('on_sale', 'sold_out');
        $m->assert('sold_out', 'on_sale');
        $this->assertTrue(true);
    }

    public function testCompletedSessionIsFinal(): void
    {
        $m = SessionStateMachine::make();

        $this->assertFalse($m->can('completed', 'on_sale'));
        $this->assertFalse($m->can('completed', 'draft'));
    }

    public function testCancelledSessionSpellsCancelledWithTwoL(): void
    {
        // ck_sessions_status says 'cancelled'. Only PAYMENTS use the one-L
        // 'canceled', and that is the provider's vocabulary, not ours.
        $m = SessionStateMachine::make();

        $this->assertFalse($m->isKnownState('canceled'));
        $this->assertTrue($m->isKnownState('cancelled'));
        $this->assertSame('cancelled', SessionStateMachine::CANCELLED);
    }
}
