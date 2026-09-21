<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * One row of `cart_items`, plus the one fact the row itself does not carry.
 *
 * The extra field is `sessionId` — the session the INVENTORY ITEM belongs to, not
 * the one the cart belongs to. It is carried separately precisely so the two can
 * be compared, because the schema cannot compare them:
 *
 *   `cart_items.inventory_item_id` -> `inventory_items.id` checks that the item
 *   EXISTS. Nothing ties `inventory_items.session_id` to `carts.session_id`.
 *   Reproduced on MySQL 8.4: a cart for session 1 accepted an item belonging to
 *   session 2. Both foreign keys stayed satisfied; the row is legal.
 *
 * Consequence at checkout: one order whose items span two performances, one
 * `seat_holds.session_id` that has to lie about at least one of them, and
 * tickets issued against the wrong date. See REVIEW-spec-bundle.md §3.16.
 *
 * WHY `totalPrice` IS STORED AT ALL: `cart_items.total_price` is a real column
 * with no CHECK tying it to `unit_price * quantity` (verified — a row with
 * `total_price = 1` beside `unit_price = 100000, quantity = 2` was accepted).
 * So the object keeps the stored value and can REPORT the disagreement
 * (`hasCoherentMoney()`) instead of silently recomputing it. A recompute would
 * hide the very defect this class exists to expose.
 */
final class CartItem
{
    public function __construct(
        public readonly int $inventoryItemId,
        public readonly int $sessionId,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly Money $totalPrice,
    ) {
        // ck_cart_items_quantity — the schema does have this one; repeated here so
        // the value object cannot exist in a state the database would refuse.
        if ($quantity < 1) {
            throw new DomainRuleViolation(
                sprintf('A cart line must have a positive quantity, got %d.', $quantity),
                'INVALID_QUANTITY'
            );
        }

        // NOT constrained by the schema: unit_price is a signed BIGINT with no
        // CHECK, and -500000 was accepted on MySQL 8.4. A negative line total is
        // a refund dressed up as a purchase.
        if ($unitPrice->isNegative()) {
            throw new DomainRuleViolation(
                sprintf(
                    'Cart line %d is priced at %s; a unit price cannot be negative.',
                    $inventoryItemId,
                    $unitPrice->toDecimal()
                ),
                'NEGATIVE_UNIT_PRICE'
            );
        }

        if ($unitPrice->currency() !== $totalPrice->currency()) {
            throw new DomainRuleViolation(
                sprintf(
                    'Cart line %d is priced in %s but totals in %s.',
                    $inventoryItemId,
                    $unitPrice->currency(),
                    $totalPrice->currency()
                ),
                'CURRENCY_MISMATCH'
            );
        }
    }

    public static function of(
        int $inventoryItemId,
        int $sessionId,
        int $quantity,
        Money $unitPrice,
        ?Money $totalPrice = null,
    ): self {
        return new self(
            $inventoryItemId,
            $sessionId,
            $quantity,
            $unitPrice,
            $totalPrice ?? $unitPrice->times($quantity),
        );
    }

    /** What the stored `total_price` ought to be. Integer minor units, no float. */
    public function expectedTotal(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }

    public function hasCoherentMoney(): bool
    {
        return $this->totalPrice->equals($this->expectedTotal());
    }

    /** How far the stored total is from arithmetic; 0 means coherent. */
    public function moneyDrift(): int
    {
        return $this->totalPrice->minorUnits() - $this->expectedTotal()->minorUnits();
    }

    public function belongsToSession(int $sessionId): bool
    {
        return $this->sessionId === $sessionId;
    }

    public function currency(): string
    {
        return $this->unitPrice->currency();
    }
}
