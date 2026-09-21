<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * Everything pricing needs to know beyond the money itself.
 *
 * Promo eligibility depends on who the customer is and what time it is, not just
 * on the numbers. Passing those facts in explicitly (instead of reaching for
 * `now()` or a session inside the evaluator) is what makes the rules testable —
 * and what stops a promo from being honoured in a unit test by accident of date.
 *
 * `customerRedemptionsOfCode` is how many times THIS customer has already
 * redeemed THIS code — `per_user_limit` is meaningless without it, and it cannot
 * be derived from `redemptions_count`, which is global.
 */
final class PricingContext
{
    /** @var list<OrderLine> */
    public readonly array $lines;

    /**
     * @param list<OrderLine> $lines
     */
    public function __construct(
        array $lines,
        public readonly \DateTimeImmutable $now,
        public readonly string $currency = 'RUB',
        public readonly int $customerPriorOrderCount = 0,
        public readonly int $customerRedemptionsOfCode = 0,
    ) {
        $normalized = [];

        foreach ($lines as $line) {
            if (! $line instanceof OrderLine) {
                throw new DomainRuleViolation('PricingContext accepts only OrderLine instances.', 'INVALID_LINE');
            }

            if ($line->currency() !== $this->currency) {
                throw new DomainRuleViolation(
                    sprintf(
                        'Order line is in %s but the context is %s. Mixed-currency carts are not supported.',
                        $line->currency(),
                        $this->currency
                    ),
                    'CURRENCY_MISMATCH'
                );
            }

            $normalized[] = $line;
        }

        $this->lines = $normalized;
    }

    /**
     * @param list<OrderLine> $lines
     */
    public static function of(
        array $lines,
        ?\DateTimeImmutable $now = null,
        string $currency = 'RUB',
        int $customerPriorOrderCount = 0,
        int $customerRedemptionsOfCode = 0,
    ): self {
        return new self(
            $lines,
            $now ?? new \DateTimeImmutable('now'),
            $currency,
            $customerPriorOrderCount,
            $customerRedemptionsOfCode
        );
    }

    public function subtotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            $total = $total->plus($line->subtotal());
        }

        return $total;
    }

    /** @return list<OrderLine> */
    public function linesMatchingEvent(?int $eventId): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (OrderLine $line): bool => $line->matchesEvent($eventId)
        ));
    }

    /** @return list<OrderLine> */
    public function linesMatchingCategory(?int $eventCategoryId): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (OrderLine $line): bool => $line->matchesCategory($eventCategoryId)
        ));
    }
}
