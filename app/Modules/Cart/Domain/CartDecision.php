<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Domain;

/**
 * Whether something may be done to a cart, and if not, why.
 *
 * A decision object rather than an exception: "may I add this seat?" is a question
 * the seat map asks before writing anything, and an exception would force the
 * caller to catch one in order to answer it.
 *
 * MERGE_REQUIRED is allowed but is NOT an insert. `uq_cart_inventory` forbids a
 * second row for the same item, so "add the same seat again" has to become an
 * UPDATE of `quantity`. Returning this instead of ALLOWED is the difference
 * between a working seat map and a 1062 rendered to the customer as a 500.
 */
final class CartDecision
{
    public const ALLOWED = 'allowed';
    public const MERGE_REQUIRED = 'merge_required';

    public const WRONG_SESSION = 'wrong_session';
    public const CART_NOT_ACTIVE = 'cart_not_active';
    public const CART_EXPIRED = 'cart_expired';
    public const INCOHERENT_MONEY = 'incoherent_money';
    public const CART_NEVER_EXPIRES = 'cart_never_expires';
    public const OUTLIVES_HOLD = 'outlives_hold';
    public const OUTLIVES_SESSION = 'outlives_session';

    private function __construct(
        public readonly string $verdict,
        public readonly bool $allowed,
        public readonly ?string $reason = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(self::ALLOWED, true);
    }

    /** Permitted, but as an UPDATE of the existing row — not a new one. */
    public static function merge(string $reason): self
    {
        return new self(self::MERGE_REQUIRED, true, $reason);
    }

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isMerge(): bool
    {
        return $this->verdict === self::MERGE_REQUIRED;
    }
}
