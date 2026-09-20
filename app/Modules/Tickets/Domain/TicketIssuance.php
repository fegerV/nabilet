<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Plans how many tickets an order produces and what index each one gets.
 *
 * One ticket per unit: an order item with quantity 4 becomes four tickets with
 * `ticket_index` 1..4 on that item.
 *
 * THE INDEX IS 1-BASED AND CONTIGUOUS PER ORDER ITEM, and both facts are enforced
 * by the database:
 *   - ck_tickets_index            ticket_index >= 1
 *   - uq_tickets_order_item_index (order_item_id, ticket_index)
 *
 * The uniqueness matters more than it looks. If two tickets on the same item ever
 * shared an index, "ticket 3 of 4" would be ambiguous in a refund — and support
 * refunds by that phrase.
 *
 * WHY THE START INDEX IS AN ARGUMENT
 *   Reissuing after a partial failure must not restart at 1: if item X already has
 *   tickets 1..4 and two more are issued, they are 5 and 6, not 1 and 2 — the
 *   first would collide with an existing row and the insert would fail. The
 *   caller passes the count already issued for that item.
 */
final class TicketIssuance
{
    /**
     * @param list<array{order_item_id: int, quantity: int}> $items
     * @param array<int, int>                                $alreadyIssued orderItemId => count
     * @return list<array{order_item_id: int, ticket_index: int}>
     */
    public function plan(array $items, array $alreadyIssued = []): array
    {
        $plan = [];

        foreach ($items as $item) {
            $orderItemId = $item['order_item_id'] ?? null;
            $quantity = $item['quantity'] ?? null;

            if (! is_int($orderItemId) || $orderItemId < 1) {
                throw new DomainRuleViolation(
                    'Each issuance item needs a positive order_item_id.',
                    'INVALID_ORDER_ITEM'
                );
            }

            if (! is_int($quantity) || $quantity < 1) {
                throw new DomainRuleViolation(
                    sprintf('Order item %d has a non-positive quantity.', $orderItemId),
                    'INVALID_QUANTITY'
                );
            }

            $start = ($alreadyIssued[$orderItemId] ?? 0) + 1;

            for ($i = 0; $i < $quantity; $i++) {
                $plan[] = [
                    'order_item_id' => $orderItemId,
                    'ticket_index' => $start + $i,
                ];
            }
        }

        return $plan;
    }

    /**
     * @param array<int, int> $alreadyIssued orderItemId => count, from the DB
     */
    public function totalCount(array $items, array $alreadyIssued = []): int
    {
        return count($this->plan($items, $alreadyIssued));
    }
}
