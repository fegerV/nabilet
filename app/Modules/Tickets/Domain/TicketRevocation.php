<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use App\Modules\Tickets\StateMachines\TicketStateMachine;

/**
 * Turns "refund N units of order item X" into per-ticket actions (ТЗ §29, §85).
 *
 * The rule that makes this more than a loop:
 *
 *   A TICKET WHOSE HOLDER WAS ADMITTED IS **REVOKED**, NOT **REFUNDED**.
 *
 * Not a stylistic choice — the schema forces it. `ck_tickets_terminal_exclusive`
 * is `NOT (used_at IS NOT NULL AND (cancelled_at IS NOT NULL OR refunded_at IS NOT
 * NULL))`, so writing `refunded_at` on a scanned ticket is rejected by the
 * database. And it is right to reject it: a ticket that says both "admitted" and
 * "refunded" is a row that claims the customer was let in and given their money
 * back, which is neither of the two things that actually happened.
 *
 * So a chargeback after the door (someone paid, entered, then reversed the charge)
 * lands as `revoked` — the one transition out of `used` — and the seat is NOT
 * returned, because the seat was consumed by a person standing in the room.
 *
 * SELECTION ORDER IS PART OF THE CONTRACT. Unused tickets are taken first, then
 * lowest `ticket_index`. Support refunds by "ticket 3 of 4" (hence
 * `uq_tickets_order_item_index`), so the result must be deterministic — and
 * burning an attendance record by revoking an admitted ticket when an unscanned
 * one was available would destroy evidence for no reason.
 *
 * Already-terminal tickets are skipped, not rejected: a refund applied twice must
 * not fail, it must do nothing the second time.
 */
final class TicketRevocation
{
    /**
     * @param list<array{order_item_id: int, quantity: int}> $requests
     * @param list<RevocableTicket>                          $tickets
     */
    public function plan(array $requests, array $tickets): RevocationPlan
    {
        /** @var list<array{ticket_id: int, from: string, to: string, returns_inventory: bool}> $actions */
        $actions = [];
        /** @var list<array{order_item_id: int, requested: int, available: int}> $shortfalls */
        $shortfalls = [];
        $claimed = [];

        foreach ($requests as $request) {
            $orderItemId = $request['order_item_id'] ?? null;
            $quantity = $request['quantity'] ?? null;

            if (! is_int($orderItemId) || $orderItemId < 1) {
                throw new DomainRuleViolation(
                    'Each revocation request needs a positive order_item_id.',
                    'INVALID_ORDER_ITEM'
                );
            }

            if (! is_int($quantity) || $quantity < 1) {
                throw new DomainRuleViolation(
                    sprintf('Order item %d: a refund must take back at least 1 ticket.', $orderItemId),
                    'INVALID_QUANTITY'
                );
            }

            $candidates = $this->candidatesFor($orderItemId, $tickets, $claimed);

            $taken = 0;
            foreach ($candidates as $ticket) {
                if ($taken >= $quantity) {
                    break;
                }

                $claimed[$ticket->id] = true;
                $taken++;

                $actions[] = [
                    'ticket_id' => $ticket->id,
                    'from' => $ticket->status,
                    'to' => $ticket->wasAdmitted()
                        ? TicketStateMachine::REVOKED
                        : TicketStateMachine::REFUNDED,
                    'returns_inventory' => ! $ticket->wasAdmitted(),
                ];
            }

            if ($taken < $quantity) {
                $shortfalls[] = [
                    'order_item_id' => $orderItemId,
                    'requested' => $quantity,
                    'available' => $taken,
                ];
            }
        }

        return RevocationPlan::of($actions, $shortfalls);
    }

    /**
     * Revocable tickets on one item: unscanned first, then lowest index.
     *
     * @param list<RevocableTicket> $tickets
     * @param array<int, bool>      $claimed ticket ids already taken by an earlier request
     * @return list<RevocableTicket>
     */
    private function candidatesFor(int $orderItemId, array $tickets, array $claimed): array
    {
        $matching = array_values(array_filter(
            $tickets,
            static fn (RevocableTicket $t): bool => $t->orderItemId === $orderItemId
                && ! isset($claimed[$t->id])
                && $t->isRevocable()
        ));

        usort($matching, static function (RevocableTicket $a, RevocableTicket $b): int {
            // 0 = issued (unused) sorts before 1 = used (admitted)
            $rank = [$a->wasAdmitted() ? 1 : 0, $b->wasAdmitted() ? 1 : 0];

            if ($rank[0] !== $rank[1]) {
                return $rank[0] <=> $rank[1];
            }

            return $a->ticketIndex <=> $b->ticketIndex;
        });

        return $matching;
    }
}
