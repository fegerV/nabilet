<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Tickets\Domain\TicketIssuance;
use Nabilet\Tests\Support\TestCase;

/**
 * Ticket index allocation.
 *
 * Small, but the failure it prevents is not: `uq_tickets_order_item_index`
 * (order_item_id, ticket_index) makes a repeated index an insert error, and
 * "ticket 3 of 4" becomes ambiguous in a refund if indices ever collide. Support
 * refunds by that phrase.
 */
final class TicketIssuanceTest extends TestCase
{
    private function issuance(): TicketIssuance
    {
        return new TicketIssuance();
    }

    public function testOneTicketPerUnit(): void
    {
        $plan = $this->issuance()->plan([
            ['order_item_id' => 1, 'quantity' => 4],
        ]);

        $this->assertCount(4, $plan);
        $this->assertSame([1, 2, 3, 4], array_column($plan, 'ticket_index'));
    }

    public function testIndicesAreOneBasedBecauseTheCheckSaysSo(): void
    {
        // ck_tickets_index: ticket_index >= 1. A zero-based allocation is a
        // constraint violation at insert time.
        $plan = $this->issuance()->plan([['order_item_id' => 1, 'quantity' => 1]]);

        $this->assertSame(1, $plan[0]['ticket_index']);
        $this->assertSame(1, min(array_column($plan, 'ticket_index')));
    }

    public function testEachOrderItemHasItsOwnSequence(): void
    {
        $plan = $this->issuance()->plan([
            ['order_item_id' => 1, 'quantity' => 2],
            ['order_item_id' => 2, 'quantity' => 3],
        ]);

        $this->assertCount(5, $plan);

        $item1 = array_values(array_filter($plan, static fn (array $p): bool => $p['order_item_id'] === 1));
        $item2 = array_values(array_filter($plan, static fn (array $p): bool => $p['order_item_id'] === 2));

        $this->assertSame([1, 2], array_column($item1, 'ticket_index'));
        $this->assertSame([1, 2, 3], array_column($item2, 'ticket_index'));
    }

    public function testIndicesAreUniqueWithinAnOrderItem(): void
    {
        $plan = $this->issuance()->plan([
            ['order_item_id' => 1, 'quantity' => 6],
            ['order_item_id' => 2, 'quantity' => 6],
        ]);

        $keys = array_map(
            static fn (array $p): string => $p['order_item_id'] . ':' . $p['ticket_index'],
            $plan
        );

        $this->assertCount(count($keys), array_unique($keys));
    }

    /**
     * Reissuing after a partial failure must continue, not restart — 1..4 already
     * exist, so the next two are 5 and 6. Restarting would collide with an
     * existing row and the insert would fail.
     */
    public function testReissueContinuesFromWhatAlreadyExists(): void
    {
        $plan = $this->issuance()->plan(
            [['order_item_id' => 1, 'quantity' => 2]],
            [1 => 4]
        );

        $this->assertSame([5, 6], array_column($plan, 'ticket_index'));
    }

    public function testReissueOfAFreshItemStillStartsAtOne(): void
    {
        $plan = $this->issuance()->plan(
            [
                ['order_item_id' => 1, 'quantity' => 2],
                ['order_item_id' => 2, 'quantity' => 1],
            ],
            [1 => 4]
        );

        $this->assertSame([5, 6, 1], array_column($plan, 'ticket_index'));
    }

    public function testZeroQuantityIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->issuance()->plan([['order_item_id' => 1, 'quantity' => 0]])
        );
    }

    public function testMissingOrderItemIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->issuance()->plan([['order_item_id' => 0, 'quantity' => 1]])
        );
    }

    public function testTotalCountMatchesThePlan(): void
    {
        $items = [
            ['order_item_id' => 1, 'quantity' => 3],
            ['order_item_id' => 2, 'quantity' => 2],
        ];

        $this->assertSame(5, $this->issuance()->totalCount($items));
    }
}
