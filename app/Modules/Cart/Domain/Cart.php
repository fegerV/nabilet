<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Orders\Domain\CartState;

/**
 * A cart as it is read back, with everything the caller needs supplied up front.
 *
 * Nothing here reaches for a clock or a repository: `now`, the session start and
 * the holds are passed in. "The cart expired four seconds ago" is then a value the
 * test can set, not a side effect of when the test happened to run.
 *
 * WHY `CartState` IS IMPORTED FROM Orders\Domain
 *   The vocabulary of `carts.status` already has one home, and duplicating it here
 *   would give a single business rule two definitions that can disagree. The
 *   direction looks backwards only if you read modules as a pipeline: Orders is
 *   where the cart's END is decided (checkout), Cart is where its contents are
 *   decided. Both ask the same question about the same status column, so both
 *   read the same class. There is no cycle — Orders never imports Cart.
 *
 * THE STATUS VOCABULARY IS A PROPOSAL, NOT A DECISION I MAY MAKE.
 *   `carts.status` is `VARCHAR(32) DEFAULT 'active'` with no CHECK, and the
 *   contract declares it as a bare string; `status='banana'` was accepted on
 *   MySQL 8.4. So this class never *validates* the status — it only asks whether
 *   it is the one value that permits writing. An unrecognised status is treated
 *   as not-active, which fails closed.
 */
final class Cart
{
    /** @var list<CartItem> */
    public readonly array $items;

    /** @param list<CartItem> $items */
    public function __construct(
        public readonly int|string $id,
        public readonly int $sessionId,
        public readonly string $status = CartState::ACTIVE,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        array $items = [],
    ) {
        foreach ($items as $item) {
            if (! $item instanceof CartItem) {
                throw new DomainRuleViolation('A cart holds only CartItem instances.', 'INVALID_CART_ITEM');
            }
        }

        $this->items = array_values($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** Only `active` permits writing. Unknown statuses fail closed. */
    public function acceptsChanges(): bool
    {
        return $this->status === CartState::ACTIVE;
    }

    public function hasExpiry(): bool
    {
        return $this->expiresAt !== null;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    /**
     * Items belonging to a session other than the cart's own.
     *
     * Empty on a well-formed cart. Non-empty is exactly the defect reproduced on
     * MySQL 8.4 — and this is the method that finds it on a cart that was ALREADY
     * written, which is the only way to detect rows created before the rule existed.
     *
     * @return list<CartItem>
     */
    public function strayItems(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (CartItem $item): bool => ! $item->belongsToSession($this->sessionId)
        ));
    }

    /** @return list<CartItem> */
    public function incoherentItems(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CartItem $item): bool => ! $item->hasCoherentMoney()
        ));
    }

    /** @return list<CartItem> */
    public function itemsFor(int $inventoryItemId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CartItem $item): bool => $item->inventoryItemId === $inventoryItemId
        ));
    }

    /** Units already in the cart for one item — 0 when it is not there. */
    public function unitsOf(int $inventoryItemId): int
    {
        $units = 0;
        foreach ($this->itemsFor($inventoryItemId) as $item) {
            $units += $item->quantity;
        }

        return $units;
    }

    /**
     * Sum of the STORED line totals, not of the recomputed ones.
     *
     * Deliberately the stored figures: if the two disagree, the checkout must see
     * the number the database holds, because that is the number that will be
     * charged if nobody stops it. Recomputing here would make the cart
     * self-consistent and the bug invisible.
     */
    public function storedTotal(string $currency = 'RUB'): Money
    {
        $total = Money::zero($currency);
        foreach ($this->items as $item) {
            $total = $total->plus($item->totalPrice);
        }

        return $total;
    }

    public function totalUnits(): int
    {
        $units = 0;
        foreach ($this->items as $item) {
            $units += $item->quantity;
        }

        return $units;
    }
}
