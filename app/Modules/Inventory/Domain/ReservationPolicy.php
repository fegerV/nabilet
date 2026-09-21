<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Whether a cart may take N units of an item, and why not (ТЗ §24).
 *
 * Returns a reason string rather than throwing, because "this seat is taken" is a
 * normal answer at a box office, not an exception — the caller turns it into a
 * 409 with a message the customer can act on.
 *
 * The two rules that are easy to miss:
 *
 *   A SEAT IS ALWAYS QUANTITY 1. `capacity` is pinned to 1 by
 *   ck_inventory_seat_capacity, so asking for two of a seat is not "two units",
 *   it is a malformed request — and if it were silently clamped to 1 the customer
 *   would be charged for one ticket while believing they bought two.
 *
 *   THE PER-ORDER CAP IS AN ANTI-SCALPING GUARD, not a technical limit. Eight is
 *   configured (NABILET_MAX_SEATS_PER_ORDER), and it belongs here rather than in a
 *   controller because it has to apply to carts, holds and orders identically —
 *   enforced in one place it is a rule, enforced in three it is a suggestion.
 */
final class ReservationPolicy
{
    public const REASON_QUANTITY_NOT_POSITIVE = 'quantity_not_positive';
    public const REASON_SEAT_QUANTITY_MUST_BE_ONE = 'seat_quantity_must_be_one';
    public const REASON_EXCEEDS_ORDER_LIMIT = 'exceeds_order_limit';
    public const REASON_INSUFFICIENT_AVAILABILITY = 'insufficient_availability';

    public function __construct(private readonly int $maxUnitsPerOrder = 8)
    {
        if ($maxUnitsPerOrder < 1) {
            throw new \Nabilet\Core\Errors\DomainRuleViolation(
                'The per-order unit limit must be at least 1.',
                'INVALID_ORDER_LIMIT'
            );
        }
    }

    /** null means "allowed"; otherwise a machine-readable reason. */
    public function refusalFor(InventoryStock $stock, int $quantity): ?string
    {
        // ck_holds_quantity: quantity > 0
        if ($quantity < 1) {
            return self::REASON_QUANTITY_NOT_POSITIVE;
        }

        if ($stock->isSeat() && $quantity !== 1) {
            return self::REASON_SEAT_QUANTITY_MUST_BE_ONE;
        }

        if ($quantity > $this->maxUnitsPerOrder) {
            return self::REASON_EXCEEDS_ORDER_LIMIT;
        }

        if (! $stock->canReserve($quantity)) {
            return self::REASON_INSUFFICIENT_AVAILABILITY;
        }

        return null;
    }

    public function allows(InventoryStock $stock, int $quantity): bool
    {
        return $this->refusalFor($stock, $quantity) === null;
    }

    /**
     * How many more units this cart may still take, given what it already holds.
     * A cart that already holds 6 of an 8-seat limit may add 2 — not 8.
     */
    public function remainingAllowance(int $alreadyTaken): int
    {
        return max(0, $this->maxUnitsPerOrder - $alreadyTaken);
    }
}
