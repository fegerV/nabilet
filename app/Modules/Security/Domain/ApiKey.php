<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain;

/**
 * One row of `api_keys`.
 *
 * The schema gets two of the four hard parts right and misses the other two:
 * `key_hash CHAR(64)` is UNIQUE (so a key is not guessable twice) and `revoked_at`
 * EXISTS — unlike `user_sessions`, which has no way to record a revocation at all
 * (§3.18). What is missing is the pair that makes those two matter:
 *
 *   `organization_id` is NULLABLE — a key can belong to nobody (accepted on
 *   MySQL 8.4). In a schema where only 16 of 64 tables carry `organization_id`,
 *   "nobody" is not a tenant, it is an ambiguity.
 *
 *   `scopes_json` is NULLABLE — and NULL is genuinely two-valued. Read as "no
 *   scopes" it grants nothing; read as "unrestricted" it grants everything. Both
 *   readings are defensible, they are opposites, and the column does not say which
 *   one the author meant. An authorization table that leaves that open is a hole
 *   waiting for the reader.
 *
 * Also proven: a key may be created with no `expires_at`; a `revoked_at` dated a
 * year ahead is accepted; `revoked_at` can be set back to NULL with no trace; and
 * a count of columns able to record last use returned **0**, so nobody can tell
 * whether a key is still in use. See `tools/repro-api-key-rules.sql`, §3.19.
 */
final class ApiKey
{
    /** @var list<string>|null null means the column is NULL, [] means JSON `[]` */
    public readonly ?array $scopes;

    /** @param list<string>|null $scopes */
    public function __construct(
        public readonly int|string $id,
        public readonly string $publicId,
        public readonly ?int $organizationId,
        public readonly string $name,
        public readonly string $keyPrefix,
        public readonly string $keyHash,
        public readonly \DateTimeImmutable $createdAt,
        ?array $scopes = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?\DateTimeImmutable $revokedAt = null,
    ) {
        $this->scopes = $scopes === null ? null : array_values($scopes);
    }

    /**
     * Any revocation is a revocation — including one dated in the future.
     *
     * A revocation is not a schedule. A row with `revoked_at` a year ahead was
     * accepted by MySQL, and reading it as "not revoked yet" would leave a key that
     * silently dies at a moment nobody is watching. The incoherence is still
     * reported separately (`revokedInTheFuture()`) so the row can be corrected.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revokedInTheFuture(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt !== null && $this->revokedAt > $now;
    }

    public function isImmortal(): bool
    {
        return $this->expiresAt === null;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    public function belongsToOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    /** Neither NULL nor empty: a key that permits nothing is not a key. */
    public function hasScopes(): bool
    {
        return $this->scopes !== null && $this->scopes !== [];
    }

    public function grants(string $scope): bool
    {
        return $this->scopes !== null && in_array($scope, $this->scopes, true);
    }

    /** Seconds until the key stops working; null when it is unbounded. */
    public function remainingSecondsAt(\DateTimeImmutable $now): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return $this->expiresAt->getTimestamp() - $now->getTimestamp();
    }
}
