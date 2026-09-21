<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * One line of a cart or order: N units of one inventory item at one price.
 *
 * The price lives on the LINE, not on the inventory item, because the item's
 * price may change after the order is placed — and an order is a historical
 * record. Recomputing yesterday's total from today's prices would silently
 * corrupt reconciliation against the payment provider.
 *
 * `eventId`/`eventCategoryId` exist so a scoped promo code can decide whether
 * this line is eligible (ТЗ §86: a code may be limited to one event or one
 * category). They are nullable because a line may be priced without them, and
 * a null simply never matches a scoped code.
 */
final class OrderLine
{
    public function __construct(
        public readonly Money $unitPrice,
        public readonly int $quantity,
        public readonly ?int $eventId = null,
        public readonly ?int $eventCategoryId = null,
        public readonly ?int $inventoryItemId = null,
    ) {
        if ($quantity < 1) {
            throw new DomainRuleViolation(
                sprintf('An order line must have a positive quantity, got %d.', $quantity),
                'INVALID_QUANTITY'
            );
        }

        if ($unitPrice->isNegative()) {
            throw new DomainRuleViolation(
                'An order line cannot have a negative unit price.',
                'INVALID_PRICE'
            );
        }
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }

    public function currency(): string
    {
        return $this->unitPrice->currency();
    }

    public function matchesEvent(?int $eventId): bool
    {
        return $eventId !== null && $this->eventId === $eventId;
    }

    public function matchesCategory(?int $eventCategoryId): bool
    {
        return $eventCategoryId !== null && $this->eventCategoryId === $eventCategoryId;
    }
}
