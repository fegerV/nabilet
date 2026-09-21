<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain;

/**
 * The contact surface of an account, and the two lifecycle markers that matter.
 *
 * Everything here is a value: no clock is read, no repository is touched. "The
 * account was deleted yesterday" is a timestamp the caller supplies, not a side
 * effect of when the check happened to run.
 *
 * THE RULE THAT SHAPED THIS CLASS
 *   A verification flag is a statement about a CONTACT, not about a person. Move
 *   the contact and the statement becomes false — but nothing in the schema makes
 *   it so: `UPDATE users SET email = 'other@example.com'` was accepted on MySQL 8.4
 *   with `email_verified_at` untouched, and so was setting `email = NULL` while the
 *   flag stayed. Both leave an account that claims to own an address nobody
 *   confirmed, or no address at all. See REVIEW §3.17.
 *
 *   So `withEmail()` clears `emailVerifiedAt` — but ONLY when the normalised value
 *   actually changed. Re-saving a profile form that did not touch the address must
 *   not de-verify the entire user base; that is the same "NO_CHANGE is not work"
 *   distinction the session rebind rule makes.
 *
 * ORPHANED FLAGS ARE REPORTED, NOT REPAIRED.
 *   A row already in the database can be in this state. If the constructor threw,
 *   such a row could not even be loaded in order to audit it. `orphanedVerifications()`
 *   is how the defect is found in data that predates the rule.
 */
final class UserIdentity
{
    public function __construct(
        public readonly int|string $id,
        public readonly ?string $email = null,
        public readonly ?\DateTimeImmutable $emailVerifiedAt = null,
        public readonly ?string $phone = null,
        public readonly ?\DateTimeImmutable $phoneVerifiedAt = null,
        public readonly ?string $passwordHash = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly string $status = 'active',
        public readonly ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function normalisedEmail(): ?string
    {
        return ContactChannel::normalise(ContactChannel::EMAIL, $this->email);
    }

    public function normalisedPhone(): ?string
    {
        return ContactChannel::normalise(ContactChannel::PHONE, $this->phone);
    }

    /**
     * An account with no way to be reached.
     *
     * Not a hypothetical: a row with `email`, `phone` and `password` all NULL was
     * accepted on MySQL 8.4, and the bundle has no OAuth/social table. Such an
     * account cannot log in, cannot be sent a ticket and cannot reset a password —
     * it can only be billed into and forgotten.
     */
    public function isReachable(): bool
    {
        return $this->normalisedEmail() !== null || $this->normalisedPhone() !== null;
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null && $this->passwordHash !== '';
    }

    /** @return list<string> channels that claim verification with no contact behind them */
    public function orphanedVerifications(): array
    {
        $orphans = [];

        if ($this->emailVerifiedAt !== null && $this->normalisedEmail() === null) {
            $orphans[] = ContactChannel::EMAIL;
        }

        if ($this->phoneVerifiedAt !== null && $this->normalisedPhone() === null) {
            $orphans[] = ContactChannel::PHONE;
        }

        return $orphans;
    }

    public function isVerifiedOn(string $channel): bool
    {
        return match ($channel) {
            ContactChannel::EMAIL => $this->emailVerifiedAt !== null && $this->normalisedEmail() !== null,
            ContactChannel::PHONE => $this->phoneVerifiedAt !== null && $this->normalisedPhone() !== null,
            default => false,
        };
    }

    // ── transitions ─────────────────────────────────────────────────────────

    public function withEmail(?string $email): self
    {
        $next = ContactChannel::normalise(ContactChannel::EMAIL, $email);
        $changed = $next !== $this->normalisedEmail();

        return new self(
            id: $this->id,
            email: $next,
            emailVerifiedAt: $changed ? null : $this->emailVerifiedAt,
            phone: $this->phone,
            phoneVerifiedAt: $this->phoneVerifiedAt,
            passwordHash: $this->passwordHash,
            firstName: $this->firstName,
            lastName: $this->lastName,
            status: $this->status,
            deletedAt: $this->deletedAt,
        );
    }

    public function withPhone(?string $phone): self
    {
        $next = ContactChannel::normalise(ContactChannel::PHONE, $phone);
        $changed = $next !== $this->normalisedPhone();

        return new self(
            id: $this->id,
            email: $this->email,
            emailVerifiedAt: $this->emailVerifiedAt,
            phone: $next,
            phoneVerifiedAt: $changed ? null : $this->phoneVerifiedAt,
            passwordHash: $this->passwordHash,
            firstName: $this->firstName,
            lastName: $this->lastName,
            status: $this->status,
            deletedAt: $this->deletedAt,
        );
    }

    public function markEmailVerified(\DateTimeImmutable $at): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            emailVerifiedAt: $at,
            phone: $this->phone,
            phoneVerifiedAt: $this->phoneVerifiedAt,
            passwordHash: $this->passwordHash,
            firstName: $this->firstName,
            lastName: $this->lastName,
            status: $this->status,
            deletedAt: $this->deletedAt,
        );
    }

    public function markPhoneVerified(\DateTimeImmutable $at): self
    {
        return new self(
            id: $this->id,
            email: $this->email,
            emailVerifiedAt: $this->emailVerifiedAt,
            phone: $this->phone,
            phoneVerifiedAt: $at,
            passwordHash: $this->passwordHash,
            firstName: $this->firstName,
            lastName: $this->lastName,
            status: $this->status,
            deletedAt: $this->deletedAt,
        );
    }

    /**
     * Erasure for a user row: remove the person, keep the row.
     *
     * This is not DELETE and it is not a soft delete either. Proven on MySQL 8.4:
     * `uq_users_email` does not include `deleted_at`, so a soft-deleted account
     * keeps BOTH the personal data AND a lock on the address — the erased person
     * cannot come back, and their data is still there. A hard DELETE would release
     * the address but is forbidden by the foreign keys from orders and tickets.
     *
     * Nulling the columns is the only shape that satisfies both obligations: the
     * personal data is gone, and because NULL does not collide in a UNIQUE index,
     * the address is released. Verified on MySQL: after anonymising in place, a new
     * account with the same address was accepted.
     */
    public function anonymised(): self
    {
        return new self(
            id: $this->id,
            email: null,
            emailVerifiedAt: null,
            phone: null,
            phoneVerifiedAt: null,
            passwordHash: null,
            firstName: null,
            lastName: null,
            status: $this->status,
            deletedAt: $this->deletedAt,
        );
    }

    /** True when this identity no longer pins any business key. */
    public function releasesContactKeys(): bool
    {
        return $this->normalisedEmail() === null && $this->normalisedPhone() === null;
    }
}
