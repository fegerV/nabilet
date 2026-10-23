<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Domain\Superseded;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * The lifecycle of a cart (ТЗ §23, §84).
 *
 * A GAP THAT HAS SINCE CLOSED — AND THIS CLASS DID NOT FOLLOW:
 *   The docblock here used to say "`carts.status` has NO CHECK constraint in the
 *   spec bundle… do not treat these four values as authoritative until
 *   ck_carts_status exists". `ck_carts_status` HAS since been added, and it
 *   allows THREE values: `active`, `converted`, `abandoned`. The fourth one
 *   below — `expired` — is not among them, so it cannot be written to the
 *   column at all: MySQL refuses the row, i.e. a 500, not a domain error.
 *   The OpenAPI contract still declares `status: { type: string }`, which is
 *   why the discrepancy stayed invisible.
 *
 *   THE LIVE OWNER IS `Nabilet\Modules\Cart\Domain\CartStatus` — it carries
 *   exactly the three schema values. This class is the ARCHIVED parallel model
 *   (moved to `Superseded/` per docs/CODE-QUALITY-GUIDE.md §9.9, kept for its
 *   unit tests and verified formulas). `EXPIRED` stays here because this
 *   cluster models the DOMAIN, not the column — but no live code may write it,
 *   and the two vocabularies must never be merged by hand again.
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
