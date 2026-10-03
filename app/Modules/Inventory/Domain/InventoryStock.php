<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Domain;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * The quantity model for one inventory item (ТЗ §24).
 *
 * A seat and a standing zone are the same shape here: `capacity` units exist and
 * `available_quantity` of them are unsold. A seat simply has capacity 1. That is
 * deliberate — the alternative, a per-unit row or a status enum per seat, forces
 * every standing purchase to touch N rows and turns "how many are left" into a
 * COUNT().
 *
 * IMMUTABLE, AND THE INVARIANTS MIRROR THE SCHEMA:
 *   ck_inventory_available_qty    available_quantity BETWEEN 0 AND capacity
 *   ck_inventory_seat_capacity    type <> 'seat' OR capacity = 1
 *   ck_inventory_type             type IN ('seat','standing')
 *
 * `reserve()` and `release()` return a NEW stock rather than mutating, so the
 * caller cannot half-apply a change: either it takes the returned value or
 * nothing moved. Under concurrency the database row is still the authority — this
 * object models what the arithmetic must be, and the UPDATE ... WHERE
 * available_quantity >= N is what makes it true.
 */
final class InventoryStock
{
    public const TYPE_SEAT = 'seat';
    public const TYPE_STANDING = 'standing';

    private function __construct(
        private readonly int $capacity,
        private readonly int $availableQuantity,
        private readonly string $type,
    ) {
    }

    public static function of(int $capacity, int $availableQuantity, string $type): self
    {
        if (! in_array($type, [self::TYPE_SEAT, self::TYPE_STANDING], true)) {
            throw new DomainRuleViolation(
                sprintf('Inventory type must be "seat" or "standing", got "%s".', $type),
                'INVALID_INVENTORY_TYPE'
            );
        }

        if ($capacity < 1) {
            throw new DomainRuleViolation(
                sprintf('Capacity must be at least 1, got %d.', $capacity),
                'INVALID_CAPACITY'
            );
        }

        // ck_inventory_seat_capacity
        if ($type === self::TYPE_SEAT && $capacity !== 1) {
            throw new DomainRuleViolation(
                sprintf('A seat has capacity 1 by definition, got %d.', $capacity),
                'INVALID_CAPACITY'
            );
        }

        // ck_inventory_available_qty
        if ($availableQuantity < 0 || $availableQuantity > $capacity) {
            throw new DomainRuleViolation(
                sprintf(
                    'available_quantity (%d) must be between 0 and capacity (%d).',
                    $availableQuantity,
                    $capacity
                ),
                'INVALID_AVAILABILITY'
            );
        }

        return new self($capacity, $availableQuantity, $type);
    }

    public static function seat(bool $available = true): self
    {
        return self::of(1, $available ? 1 : 0, self::TYPE_SEAT);
    }

    public static function standing(int $capacity, ?int $available = null): self
    {
        return self::of($capacity, $available ?? $capacity, self::TYPE_STANDING);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function available(): int
    {
        return $this->availableQuantity;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isSeat(): bool
    {
        return $this->type === self::TYPE_SEAT;
    }

    public function isSoldOut(): bool
    {
        return $this->availableQuantity === 0;
    }

    public function canReserve(int $quantity): bool
    {
        return $quantity > 0 && $quantity <= $this->availableQuantity;
    }

    /**
     * @throws ConflictError when there is not enough left — the caller turns that
     *                       into "seat unavailable", never into a negative stock
     */
    public function reserve(int $quantity): self
    {
        if ($quantity < 1) {
            throw new DomainRuleViolation(
                sprintf('A reservation must take at least 1 unit, got %d.', $quantity),
                'INVALID_QUANTITY'
            );
        }

        if (! $this->canReserve($quantity)) {
            throw ConflictError::seatUnavailable(
                '',
                ['requested' => $quantity, 'available' => $this->availableQuantity]
            );
        }

        return new self($this->capacity, $this->availableQuantity - $quantity, $this->type);
    }

    /**
     * Return units to the pool — a released or expired hold.
     *
     * Capped at capacity rather than throwing: over-releasing would otherwise be
     * a hard failure inside the sweeper, and the sweeper must not stop because one
     * row drifted. The invariant `available <= capacity` is what matters.
     */
    public function release(int $quantity): self
    {
        if ($quantity < 0) {
            throw new DomainRuleViolation(
                sprintf('Cannot release a negative quantity (%d).', $quantity),
                'INVALID_QUANTITY'
            );
        }

        $restored = min($this->capacity, $this->availableQuantity + $quantity);

        return new self($this->capacity, $restored, $this->type);
    }
}
