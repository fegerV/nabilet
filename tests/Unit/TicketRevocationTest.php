<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Tickets\Domain\RevocableTicket;
use Nabilet\Modules\Tickets\Domain\TicketRevocation;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * What a refund does to tickets — specifically, to the seats behind them.
 *
 * The rule under test: an admitted holder's ticket is REVOKED and its seat stays
 * sold; an unscanned ticket is REFUNDED and its seat goes back on sale. Getting
 * this backwards produces either a seat sold twice (someone is standing in it) or
 * a row that claims the customer was let in and refunded, which
 * `ck_tickets_terminal_exclusive` exists to make impossible.
 */
final class TicketRevocationTest extends TestCase
{
    private const ITEM_A = 11;
    private const ITEM_B = 22;

    private function ticket(int $id, int $orderItemId, int $index, string $status = TicketStateMachine::ISSUED): RevocableTicket
    {
        return new RevocableTicket($id, $orderItemId, $index, $status);
    }

    /** @param list<RevocableTicket> $tickets */
    private function plan(array $requests, array $tickets): \Nabilet\Modules\Tickets\Domain\RevocationPlan
    {
        return (new TicketRevocation())->plan($requests, $tickets);
    }

    // ── the central rule ─────────────────────────────────────────────────────

    public function testAnUnscannedTicketIsRefundedAndItsSeatReturns(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 1]],
            [$this->ticket(1, self::ITEM_A, 1)]
        );

        $this->assertTrue($plan->isComplete());
        $this->assertSame(TicketStateMachine::REFUNDED, $plan->actions()[0]['to']);
        $this->assertTrue($plan->actions()[0]['returns_inventory']);
        $this->assertSame([1], $plan->ticketIdsReturningInventory());
        $this->assertSame([], $plan->admittedTicketIds());
    }

    /**
     * Chargeback after the door. `ck_tickets_terminal_exclusive` forbids writing
     * refunded_at next to used_at, and it is right to: the seat was consumed by a
     * person standing in the room.
     */
    public function testAnAdmittedTicketIsRevokedAndItsSeatStaysSold(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 1]],
            [$this->ticket(1, self::ITEM_A, 1, TicketStateMachine::USED)]
        );

        $this->assertSame(TicketStateMachine::REVOKED, $plan->actions()[0]['to']);
        $this->assertFalse($plan->actions()[0]['returns_inventory']);
        $this->assertSame([], $plan->ticketIdsReturningInventory());
        $this->assertSame([1], $plan->admittedTicketIds());
    }

    /** Unscanned tickets are consumed first — never burn an attendance record
     *  while an untouched ticket is still available. */
    public function testUnscannedTicketsAreTakenBeforeAdmittedOnes(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 1]],
            [
                $this->ticket(1, self::ITEM_A, 1, TicketStateMachine::USED),
                $this->ticket(2, self::ITEM_A, 2),
            ]
        );

        $this->assertSame(2, $plan->actions()[0]['ticket_id']);
        $this->assertSame(TicketStateMachine::REFUNDED, $plan->actions()[0]['to']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    /** Support refunds by "ticket 3 of 4", so the pick must be stable. */
    public function testTicketsAreTakenInIndexOrder(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 2]],
            [
                $this->ticket(3, self::ITEM_A, 3),
                $this->ticket(1, self::ITEM_A, 1),
                $this->ticket(2, self::ITEM_A, 2),
            ]
        );

        $this->assertSame([1, 2], array_column($plan->actions(), 'ticket_id'));
    }

    public function testEachItemIsRefundedFromItsOwnTickets(): void
    {
        $plan = $this->plan(
            [
                ['order_item_id' => self::ITEM_A, 'quantity' => 1],
                ['order_item_id' => self::ITEM_B, 'quantity' => 1],
            ],
            [
                $this->ticket(1, self::ITEM_A, 1),
                $this->ticket(2, self::ITEM_B, 1),
            ]
        );

        $this->assertSame([1, 2], array_column($plan->actions(), 'ticket_id'));
        $this->assertTrue($plan->isComplete());
    }

    public function testOneTicketIsNeverClaimedByTwoRequests(): void
    {
        $plan = $this->plan(
            [
                ['order_item_id' => self::ITEM_A, 'quantity' => 2],
                ['order_item_id' => self::ITEM_A, 'quantity' => 2],
            ],
            [
                $this->ticket(1, self::ITEM_A, 1),
                $this->ticket(2, self::ITEM_A, 2),
            ]
        );

        $this->assertSame([1, 2], array_column($plan->actions(), 'ticket_id'));
        $this->assertFalse($plan->isComplete(), 'the second request cannot be satisfied');
    }

    // ── already finished ─────────────────────────────────────────────────────

    /** A refund applied twice must do nothing the second time, not fail. */
    public function testTerminalTicketsAreSkippedRatherThanRejected(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 1]],
            [
                $this->ticket(1, self::ITEM_A, 1, TicketStateMachine::REFUNDED),
                $this->ticket(2, self::ITEM_A, 2),
            ]
        );

        $this->assertSame([2], array_column($plan->actions(), 'ticket_id'));
        $this->assertTrue($plan->isComplete());
    }

    public function testARevokedTicketIsNotRevokedAgain(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 1]],
            [$this->ticket(1, self::ITEM_A, 1, TicketStateMachine::REVOKED)]
        );

        $this->assertTrue($plan->isEmpty());
        $this->assertFalse($plan->isComplete());
    }

    public function testAnExpiredTicketIsNotRefundable(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 1]],
            [$this->ticket(1, self::ITEM_A, 1, TicketStateMachine::EXPIRED)]
        );

        $this->assertTrue($plan->isEmpty());
    }

    // ── shortfalls ───────────────────────────────────────────────────────────

    /** Reported, not thrown: money and tickets must not be left disagreeing. */
    public function testAShortfallIsReportedNotThrown(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 3]],
            [
                $this->ticket(1, self::ITEM_A, 1),
                $this->ticket(2, self::ITEM_A, 2),
            ]
        );

        $this->assertFalse($plan->isComplete());
        $this->assertSame(
            [['order_item_id' => self::ITEM_A, 'requested' => 3, 'available' => 2]],
            $plan->shortfalls()
        );
        $this->assertCount(2, $plan->actions());
    }

    // ── mixed outcomes ───────────────────────────────────────────────────────

    /** One refund, two seats, one of them already used: split outcome. */
    public function testAMixedRefundSplitsInventoryAndAttendance(): void
    {
        $plan = $this->plan(
            [['order_item_id' => self::ITEM_A, 'quantity' => 2]],
            [
                // only one unscanned ticket, so the second seat must come from an
                // admitted one — that is the split this test is about
                $this->ticket(1, self::ITEM_A, 1, TicketStateMachine::USED),
                $this->ticket(2, self::ITEM_A, 2),
                $this->ticket(3, self::ITEM_A, 3, TicketStateMachine::USED),
            ]
        );

        $this->assertSame([2, 1], array_column($plan->actions(), 'ticket_id'), 'unscanned first, then lowest index');
        $this->assertSame([TicketStateMachine::REFUNDED, TicketStateMachine::REVOKED], array_column($plan->actions(), 'to'));
        $this->assertSame([2], $plan->ticketIdsReturningInventory());
        $this->assertSame([1], $plan->admittedTicketIds());
    }

    // ── input invariants ─────────────────────────────────────────────────────

    public function testAZeroQuantityRefundIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->plan([['order_item_id' => self::ITEM_A, 'quantity' => 0]], [])
        );
    }

    public function testAnUnknownTicketStatusIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->ticket(1, self::ITEM_A, 1, 'voided')
        );
    }

    public function testWasAdmittedAndIsRevocableAgree(): void
    {
        $used = $this->ticket(1, self::ITEM_A, 1, TicketStateMachine::USED);

        $this->assertTrue($used->wasAdmitted());
        $this->assertTrue($used->isRevocable(), 'a used ticket can still be revoked');

        $refunded = $this->ticket(2, self::ITEM_A, 2, TicketStateMachine::REFUNDED);

        $this->assertFalse($refunded->isRevocable());
    }
}
