<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One row of `seat_holds` — units of an inventory item set aside for one cart.
 *
 * The lifetime has THREE distinct ends, and conflating them is where the checkout
 * bugs live:
 *
 *   expires_at      the hold stops being honoured for NEW attempts
 *   released_at     the sweeper returned the units to the pool (or the cart died)
 *   converted_at    the units became an order and are no longer merely held
 *
 * `ck_holds_quantity` requires quantity > 0. There is no CHECK tying a hold to a
 * cart's life, so the two timestamps above are the only record of what happened,
 * and every consumer must read them.
 *
 * WHY CONVERSION IS STILL ALLOWED INSIDE THE GRACE WINDOW
 *   HoldWindow separates `expires_at` (honoured) from `expires_at + grace`
 *   (releasable). A buyer who hits "pay" a few seconds after `expires_at` has not
 *   lost anything: the sweeper has not run, the units are still debited from
 *   `available_quantity` in this cart's favour, and no other cart could have taken
 *   them. Refusing there would be a false negative on a real sale. What must never
 *   be allowed is converting after `releasable_at`, because from that moment the
 *   units may belong to somebody else.
 */
final class SeatHold
{
    public function __construct(
        public readonly int|string $id,
        public readonly int $inventoryItemId,
        public readonly int $quantity,
        public readonly HoldWindow $window,
        public readonly ?\DateTimeImmutable $releasedAt = null,
        public readonly ?\DateTimeImmutable $convertedAt = null,
    ) {
        // ck_holds_quantity
        if ($quantity < 1) {
            throw new DomainRuleViolation(
                sprintf('A hold must cover at least 1 unit, got %d.', $quantity),
                'INVALID_QUANTITY'
            );
        }
    }

    public function isReleased(): bool
    {
        return $this->releasedAt !== null;
    }

    public function isConverted(): bool
    {
        return $this->convertedAt !== null;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->window->expiresAt();
    }

    /**
     * May this hold still be turned into an order?
     *
     * Not released, not already converted, and not yet returned to the pool by the
     * sweeper. Note this deliberately permits the grace window — see the class
     * docblock for why that is the safer of the two possible rules.
     */
    public function isConvertibleAt(\DateTimeImmutable $now): bool
    {
        return ! $this->isReleased()
            && ! $this->isConverted()
            && ! $this->window->isReleasableAt($now);
    }

    /**
     * Past `expires_at` but still convertible. True only inside the grace window:
     * the units are still ours, but the customer has technically overrun. Worth
     * surfacing rather than hiding — it is the population that shows up as "paid
     * late" in support tickets.
     */
    public function isOverrunAt(\DateTimeImmutable $now): bool
    {
        return $this->window->isExpiredAt($now) && $this->isConvertibleAt($now);
    }

    public function covers(int $inventoryItemId): bool
    {
        return $this->inventoryItemId === $inventoryItemId;
    }
}
