<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

/**
 * Which tickets a refund takes back, and what that does to the seat map.
 *
 * `returnsInventory` is per ticket and is the whole reason this plan exists.
 * A ticket that was never scanned goes back on sale; a ticket whose holder was
 * already admitted does NOT — the person attended, the seat was consumed, and
 * putting it back would sell the same seat twice to someone standing in the room
 * where the first buyer is sitting.
 *
 * `shortfalls` are reported rather than thrown. A refund of "2 seats" against an
 * item where one is already revoked is a normal support situation, and blowing up
 * would leave the money and the tickets disagreeing with no way forward.
 */
final class RevocationPlan
{
    /**
     * @param list<array{ticket_id: int, from: string, to: string, returns_inventory: bool}> $actions
     * @param list<array{order_item_id: int, requested: int, available: int}>                 $shortfalls
     */
    private function __construct(
        private readonly array $actions,
        private readonly array $shortfalls,
    ) {
    }

    /**
     * @param list<array{ticket_id: int, from: string, to: string, returns_inventory: bool}> $actions
     * @param list<array{order_item_id: int, requested: int, available: int}>                 $shortfalls
     */
    public static function of(array $actions, array $shortfalls = []): self
    {
        return new self($actions, $shortfalls);
    }

    /** @return list<array{ticket_id: int, from: string, to: string, returns_inventory: bool}> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return list<array{order_item_id: int, requested: int, available: int}> */
    public function shortfalls(): array
    {
        return $this->shortfalls;
    }

    public function isComplete(): bool
    {
        return $this->shortfalls === [];
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /** @return list<int> ticket ids whose units must go back into the pool */
    public function ticketIdsReturningInventory(): array
    {
        return array_values(array_map(
            static fn (array $action): int => $action['ticket_id'],
            array_filter($this->actions, static fn (array $a): bool => $a['returns_inventory'])
        ));
    }

    /** @return list<int> tickets whose holder was admitted and whose seat stays sold */
    public function admittedTicketIds(): array
    {
        return array_values(array_map(
            static fn (array $action): int => $action['ticket_id'],
            array_filter($this->actions, static fn (array $a): bool => ! $a['returns_inventory'])
        ));
    }
}
