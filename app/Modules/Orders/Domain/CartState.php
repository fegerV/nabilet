<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * The lifecycle of a cart (ТЗ §23, §84).
 *
 * A GAP, NOT A DECISION I AM ENTITLED TO MAKE:
 *   `carts.status` is `VARCHAR(32) NOT NULL DEFAULT 'active'` with NO CHECK
 *   constraint in the spec bundle, and the OpenAPI contract declares it as a bare
 *   `status: { type: string }`. So the vocabulary below is *proposed*, not
 *   spec-backed — the same way `hold` states are. It is recorded as an open
 *   decision in docs/REVIEW-spec-bundle.md; do not treat these four values as
 *   authoritative until ck_carts_status exists in the bundle.
 *
 * WHY THE VOCABULARY IS THIS SMALL:
 *   Every state here exists because some other component behaves differently
 *   because of it. `converted` stops a double checkout; `expired` is what the
 *   sweeper sets when the hold window closed and the seats went back;
 *   `abandoned` is a cart the customer walked away from and which must never be
 *   resurrected with stale prices. A cart has no "paid" state — payment is a fact
 *   about the ORDER, and duplicating it here is how the two drift apart.
 *
 * ONLY `active` MAY BE CHECKED OUT. That single rule is the guard against the
 * most expensive bug in this flow: a retried checkout (double-clicked button,
 * retried request, replayed idempotent POST) creating a second order from one
 * cart and selling the same seats twice.
 */
final class CartState
{
    public const ACTIVE = 'active';
    public const CONVERTED = 'converted';
    public const ABANDONED = 'abandoned';
    public const EXPIRED = 'expired';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::CONVERTED, self::ABANDONED, self::EXPIRED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function canCheckOut(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    /** @return list<string> states a cart may still move into */
    public static function transitionsFrom(string $status): array
    {
        return match ($status) {
            self::ACTIVE => [self::CONVERTED, self::ABANDONED, self::EXPIRED],
            // Terminal on purpose: a converted cart is history. If the customer
            // wants to buy again, that is a NEW cart against current prices.
            self::CONVERTED, self::ABANDONED, self::EXPIRED => [],
            default => throw new DomainRuleViolation(
                sprintf('Unknown cart status "%s".', $status),
                'INVALID_CART_STATUS'
            ),
        };
    }

    public static function isTerminal(string $status): bool
    {
        return self::transitionsFrom($status) === [];
    }
}
