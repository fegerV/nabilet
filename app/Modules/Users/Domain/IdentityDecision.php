<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain;

/**
 * Whether an account may take an action, and if not, why.
 *
 * A decision object rather than an exception: "may I register with this address?"
 * is a question the form asks before writing, and an exception would force the
 * caller to catch one in order to answer it.
 *
 * WHY EMAIL_TAKEN_BY_DELETED IS ITS OWN VERDICT
 *   `uq_users_email` does not include `deleted_at`, so a soft-deleted account keeps
 *   its address. The database collapses two very different situations into one
 *   error, 1062: "somebody is using this address" and "an account that no longer
 *   exists is using this address". The first means "log in instead"; the second
 *   means the tombstone has to be purged or anonymised. Support cannot tell them
 *   apart from the error message, which is why they are separated here.
 */
final class IdentityDecision
{
    public const ALLOWED = 'allowed';

    public const EMAIL_TAKEN = 'email_taken';
    public const EMAIL_TAKEN_BY_DELETED = 'email_taken_by_deleted';
    public const NOT_REACHABLE = 'not_reachable';
    public const ACCOUNT_DELETED = 'account_deleted';
    public const ORPHANED_VERIFICATION = 'orphaned_verification';

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

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
