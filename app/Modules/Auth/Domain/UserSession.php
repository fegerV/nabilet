<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * One row of `user_sessions`.
 *
 * The columns are: `session_token_hash`, `device_name`, `user_agent`, `ip_address`,
 * `last_seen_at`, `expires_at`, `created_at`. There is NO status column and NO
 * revocation column — verified against MySQL 8.4, where a count of columns able to
 * record a revocation returned **0** and `UPDATE user_sessions SET revoked_at = …`
 * failed with `ERROR 1054 Unknown column`.
 *
 * So the lifetime of a session is three timestamps and nothing else, which makes
 * every one of them load-bearing:
 *
 *   created_at     when the session began
 *   last_seen_at   the last request that used it (nullable — may never have been used)
 *   expires_at     when it stops being honoured (NULLABLE — and NULL was accepted)
 *
 * A NULL `expires_at` is not "a long session", it is an unbounded one: a token that
 * outlives the device, the password and the incident. That is why `isImmortal()`
 * exists as its own question rather than as a flavour of "not expired".
 */
final class UserSession
{
    public function __construct(
        public readonly int|string $id,
        public readonly int|string $userId,
        public readonly string $tokenHash,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?\DateTimeImmutable $lastSeenAt = null,
        public readonly ?string $deviceName = null,
    ) {
    }

    /** No expiry means no bound: this token can be used forever. */
    public function isImmortal(): bool
    {
        return $this->expiresAt === null;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    /**
     * A row whose expiry precedes its own creation.
     *
     * Accepted by MySQL 8.4. It is not merely odd: any query that trusts
     * `expires_at` computes a session that was never valid, and any query that
     * trusts `created_at` computes one that is valid forever. The two answers
     * disagree, so which one wins depends on which line of code asks.
     */
    public function expiresBeforeItWasCreated(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < $this->createdAt;
    }

    /**
     * Used after it stopped being honoured.
     *
     * Accepted by MySQL 8.4 (a row with `last_seen_at` three days after
     * `expires_at` was inserted without complaint). Either the expiry is not being
     * enforced anywhere, or the token is being replayed — both are worth a report
     * rather than a silent "not expired".
     */
    public function usedAfterExpiry(): bool
    {
        return $this->expiresAt !== null
            && $this->lastSeenAt !== null
            && $this->lastSeenAt > $this->expiresAt;
    }

    /** Seconds since the last request that used this session; null if never used. */
    public function idleSecondsAt(\DateTimeImmutable $now): ?int
    {
        if ($this->lastSeenAt === null) {
            return null;
        }

        return $now->getTimestamp() - $this->lastSeenAt->getTimestamp();
    }

    /** Seconds from now until the session stops being honoured; null if immortal. */
    public function remainingSecondsAt(\DateTimeImmutable $now): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return $this->expiresAt->getTimestamp() - $now->getTimestamp();
    }

    /** Never used since it was issued. */
    public function isUnused(): bool
    {
        return $this->lastSeenAt === null;
    }
}
