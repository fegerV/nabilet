<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use App\Modules\Inventory\Domain\InventoryStock;

/**
 * One cart line re-examined at the moment of checkout.
 *
 * A cart line carries a price snapshot (`cart_items.unit_price`), but a cart can
 * sit open for the whole 10-minute hold window while an organizer changes the
 * price underneath it. So checkout must compare two prices and decide, per line:
 *
 *   current == snapshot   charge it, nothing to say
 *   current <  snapshot   charge the LOWER current price
 *   current >  snapshot   refuse the checkout and make the customer re-confirm
 *
 * The asymmetry is deliberate. Charging less than the customer saw is a pleasant
 * surprise and costs the organizer nothing they had not already agreed to sell at.
 * Charging MORE than the number they clicked "pay" against is the kind of thing
 * that produces chargebacks and regulator interest; it must be an explicit
 * re-confirmation, never a silent difference.
 *
 * `stock` is the live availability of the item, so the same object answers
 * "can this still be sold" and "is this a seat or a standing zone" without the
 * evaluator reaching for a repository.
 */
final class CheckoutLine
{
    public function __construct(
        public readonly int $inventoryItemId,
        public readonly int $quantity,
        public readonly Money $snapshotUnitPrice,
        public readonly Money $currentUnitPrice,
        public readonly InventoryStock $stock,
    ) {
        if ($quantity < 1) {
            throw new DomainRuleViolation(
                sprintf('A checkout line must have a positive quantity, got %d.', $quantity),
                'INVALID_QUANTITY'
            );
        }

        if ($snapshotUnitPrice->currency() !== $currentUnitPrice->currency()) {
            throw new DomainRuleViolation(
                sprintf(
                    'Line %d: the cart priced it in %s but the inventory item is in %s.',
                    $inventoryItemId,
                    $snapshotUnitPrice->currency(),
                    $currentUnitPrice->currency()
                ),
                'CURRENCY_MISMATCH'
            );
        }
    }

    public static function of(
        int $inventoryItemId,
        int $quantity,
        Money $snapshotUnitPrice,
        ?Money $currentUnitPrice = null,
        ?InventoryStock $stock = null,
    ): self {
        return new self(
            $inventoryItemId,
            $quantity,
            $snapshotUnitPrice,
            $currentUnitPrice ?? $snapshotUnitPrice,
            $stock ?? InventoryStock::standing(max(1, $quantity), max(1, $quantity)),
        );
    }

    public function currency(): string
    {
        return $this->snapshotUnitPrice->currency();
    }

    /**
     * Positive = the price went UP since the cart was built (must re-confirm);
     * negative = it came down (charge the lower price).
     */
    public function priceDrift(): int
    {
        return $this->currentUnitPrice->minorUnits() - $this->snapshotUnitPrice->minorUnits();
    }

    public function priceRose(): bool
    {
        return $this->priceDrift() > 0;
    }

    public function priceFell(): bool
    {
        return $this->priceDrift() < 0;
    }

    /** What the customer is actually charged per unit — never more than they saw. */
    public function effectiveUnitPrice(): Money
    {
        return $this->priceFell() ? $this->currentUnitPrice : $this->snapshotUnitPrice;
    }

    public function effectiveSubtotal(): Money
    {
        return $this->effectiveUnitPrice()->times($this->quantity);
    }

    public function canStillBeSold(): bool
    {
        return $this->stock->canReserve($this->quantity);
    }
}
