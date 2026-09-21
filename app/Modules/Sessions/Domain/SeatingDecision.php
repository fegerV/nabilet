<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Domain;

/**
 * Whether a change to a session's seating is permitted, and if not, why.
 *
 * A decision object rather than an exception: "may I open this session for sale?"
 * is a question the admin UI asks before doing anything, and an exception would
 * make the caller catch one to answer it.
 *
 * NO_CHANGE is allowed but is not work. It exists so that saving a session form
 * twice does not look like a rebind and does not write an UPDATE that changes
 * nothing and bumps `updated_at`.
 */
final class SeatingDecision
{
    public const ALLOWED = 'allowed';
    public const NO_CHANGE = 'no_change';
    public const WRONG_HALL = 'wrong_hall';
    public const SCHEMA_NOT_PUBLISHED = 'schema_not_published';
    public const INVENTORY_EXISTS = 'inventory_exists';
    public const SESSION_TERMINAL = 'session_terminal';

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

    public static function noChange(string $reason): self
    {
        return new self(self::NO_CHANGE, true, $reason);
    }

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /** True when there is genuinely something to write. */
    public function requiresWrite(): bool
    {
        return $this->verdict === self::ALLOWED;
    }
}
