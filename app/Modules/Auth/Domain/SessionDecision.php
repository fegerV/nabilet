<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * Whether something may be done to a session, and if not, why.
 *
 * A decision object rather than an exception: "may this token still be used?" is a
 * question the authentication middleware asks on every request, and an exception
 * would mean catching one to answer it.
 *
 * REVOKABLE_ONLY_BY_DELETE is allowed but is NOT the work the caller expects.
 * `user_sessions` has no revocation column, so "log out this device" is a DELETE —
 * which destroys the only record that the session existed. Saying so here is what
 * makes the caller write the audit line (into `login_logs`, the table that does
 * exist) before deleting. It plays the same role as `MERGE_REQUIRED` in the cart:
 * permitted, but the write is a different one.
 */
final class SessionDecision
{
    public const ALLOWED = 'allowed';
    public const REVOKABLE_ONLY_BY_DELETE = 'revokable_only_by_delete';

    public const NO_EXPIRY = 'no_expiry';
    public const EXPIRES_BEFORE_CREATED = 'expires_before_created';
    public const EXPIRED = 'expired';
    public const USED_AFTER_EXPIRY = 'used_after_expiry';

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

    /** Permitted, but the write is a DELETE and the caller must audit first. */
    public static function revokableByDelete(string $reason): self
    {
        return new self(self::REVOKABLE_ONLY_BY_DELETE, true, $reason);
    }

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function requiresDelete(): bool
    {
        return $this->verdict === self::REVOKABLE_ONLY_BY_DELETE;
    }
}
