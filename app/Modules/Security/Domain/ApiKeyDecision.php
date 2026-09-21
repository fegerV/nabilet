<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain;

/**
 * Whether an API key may be used, issued or revoked, and if not, why.
 *
 * A decision object rather than an exception: "does this key permit this?" is asked
 * on every request, and an exception would mean catching one to answer it.
 *
 * NO_CHANGE is allowed but is not work — revoking an already-revoked key must not
 * look like a fresh revocation, the same distinction the session rebind and the
 * contact change both make.
 */
final class ApiKeyDecision
{
    public const ALLOWED = 'allowed';
    public const NO_CHANGE = 'no_change';

    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';
    public const NO_EXPIRY = 'no_expiry';
    public const NO_SCOPES = 'no_scopes';
    public const NO_ORGANIZATION = 'no_organization';
    public const SCOPE_NOT_GRANTED = 'scope_not_granted';
    public const REVOCATION_IS_TERMINAL = 'revocation_is_terminal';
    public const REVOKED_IN_THE_FUTURE = 'revoked_in_the_future';

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
