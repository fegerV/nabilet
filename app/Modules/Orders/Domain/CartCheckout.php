<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Inventory\Domain\SeatHold;

/**
 * Everything checkout must know, assembled once and passed in.
 *
 * Nothing here reaches for the clock, a repository or a session — `now`, the live
 * prices and the live holds are all supplied by the caller. That is what makes the
 * decision testable: "the hold expired two seconds ago" is a value, not a side
 * effect of when the test happened to run.
 *
 * The holds are passed separately from the lines rather than joined by
 * `inventory_item_id` inside the evaluator, because a mismatch between what the
 * cart wants and what is actually held is precisely one of the outcomes being
 * detected. Collapsing them first would make that case unrepresentable.
 *
 * The per-order unit cap is NOT here: it belongs to ReservationPolicy, which the
 * evaluator owns. Carrying it in both places would give the same business rule
 * two homes that can disagree.
 */
final class CartCheckout
{
    /** @var list<CheckoutLine> */
    public readonly array $lines;

    /** @var list<SeatHold> */
    public readonly array $holds;

    /**
     * @param list<CheckoutLine> $lines
     * @param list<SeatHold>     $holds
     */
    public function __construct(
        public readonly int|string $cartId,
        public readonly string $cartStatus,
        array $lines,
        array $holds,
        public readonly \DateTimeImmutable $now,
        public readonly string $currency = 'RUB',
    ) {
        foreach ($lines as $line) {
            if (! $line instanceof CheckoutLine) {
                throw new DomainRuleViolation('CartCheckout accepts only CheckoutLine instances.', 'INVALID_LINE');
            }

            if ($line->currency() !== $this->currency) {
                throw new DomainRuleViolation(
                    sprintf(
                        'Cart %s is in %s but line %d is priced in %s.',
                        (string) $cartId,
                        $this->currency,
                        $line->inventoryItemId,
                        $line->currency()
                    ),
                    'CURRENCY_MISMATCH'
                );
            }
        }

        foreach ($holds as $hold) {
            if (! $hold instanceof SeatHold) {
                throw new DomainRuleViolation('CartCheckout accepts only SeatHold instances.', 'INVALID_HOLD');
            }
        }

        $this->lines = array_values($lines);
        $this->holds = array_values($holds);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Every hold that covers this item, including dead ones.
     *
     * Deliberately NOT filtered by convertibility: "a hold exists but is dead" and
     * "no hold was ever taken" are different answers for the customer — the first
     * means their selection timed out, the second is a bug or a direct POST.
     */
    public function holdsFor(int $inventoryItemId): array
    {
        return array_values(array_filter(
            $this->holds,
            static fn (SeatHold $hold): bool => $hold->covers($inventoryItemId)
        ));
    }
}
