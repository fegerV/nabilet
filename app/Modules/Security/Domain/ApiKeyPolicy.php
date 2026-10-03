<?php

declare(strict_types=1);

namespace Nabilet\Modules\Security\Domain;

/**
 * What an API key may do (ТЗ §78, §79).
 *
 * Every gap these rules close was reproduced against MySQL 8.4 first, in
 * `tools/repro-api-key-rules.sql`; they are collected in REVIEW §3.19.
 *
 * ORDER OF THE CHECKS IS PART OF THE CONTRACT.
 *   An explicit human act outranks a clock: revocation first, then whether the key
 *   is bounded at all, then whether it is inside its window. Only then does scope
 *   get asked, because "may this key do X" presumes the key is still alive. The
 *   order is asserted by a test, because reordering changes the message a caller
 *   receives.
 */
final class ApiKeyPolicy
{
    /** May this key be used at all right now? */
    public function useDecision(ApiKey $key, \DateTimeImmutable $now): ApiKeyDecision
    {
        if ($key->isRevoked()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::REVOKED,
                sprintf('Key %s was revoked at %s.', (string) $key->id, $key->revokedAt?->format('c') ?? '—')
            );
        }

        // expires_at is nullable and NULL was accepted: an unbounded credential.
        if ($key->isImmortal()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::NO_EXPIRY,
                sprintf('Key %s has no expires_at; a credential that cannot expire cannot be retired.', (string) $key->id)
            );
        }

        if ($key->isExpiredAt($now)) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::EXPIRED,
                sprintf('Key %s expired at %s.', (string) $key->id, $key->expiresAt?->format('c') ?? '—')
            );
        }

        // NULL is two-valued: nothing, or everything. Fail closed on the reading
        // that would be catastrophic if the other one was meant.
        if (! $key->hasScopes()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::NO_SCOPES,
                sprintf(
                    'Key %s carries no scopes: scopes_json is %s and the schema does not say '
                    . 'whether that means nothing or everything.',
                    (string) $key->id,
                    $key->scopes === null ? 'NULL' : '[]'
                )
            );
        }

        return ApiKeyDecision::allowed();
    }

    /** May this key perform one specific action? */
    public function scopeDecision(ApiKey $key, string $scope, \DateTimeImmutable $now): ApiKeyDecision
    {
        $usable = $this->useDecision($key, $now);
        if (! $usable->isAllowed()) {
            return $usable;
        }

        if (! $key->grants($scope)) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::SCOPE_NOT_GRANTED,
                sprintf('Key %s does not carry the scope "%s".', (string) $key->id, $scope)
            );
        }

        return ApiKeyDecision::allowed();
    }

    /**
     * May this key be issued?
     *
     * A key with no organization is refused. This is the assumption worth stating:
     * the column is merely nullable, so today an orgless key is an accident rather
     * than a deliberate system key, and in a schema where only 16 of 64 tables even
     * know about tenancy "no organization" is not a tenant. If global keys are
     * wanted, they should be a decision recorded in the bundle, not a side effect
     * of a nullable column.
     */
    public function issueDecision(ApiKey $key, \DateTimeImmutable $now): ApiKeyDecision
    {
        if (! $key->belongsToOrganization()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::NO_ORGANIZATION,
                sprintf('Key %s belongs to no organization, so its reach is undefined.', (string) $key->id)
            );
        }

        if ($key->isImmortal()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::NO_EXPIRY,
                sprintf('Key %s must have an expiry.', (string) $key->id)
            );
        }

        if ($key->isExpiredAt($now)) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::EXPIRED,
                sprintf('Key %s is issued already expired.', (string) $key->id)
            );
        }

        if (! $key->hasScopes()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::NO_SCOPES,
                sprintf('Key %s would grant nothing; issue it with at least one scope.', (string) $key->id)
            );
        }

        return ApiKeyDecision::allowed();
    }

    /**
     * Revoking a key.
     *
     * `api_keys` DOES have `revoked_at` — unlike `user_sessions` (§3.18). But there
     * is no history column, so setting it back to NULL erases the fact that the key
     * was ever revoked; a count of columns recording last use returned 0, so nobody
     * can tell whether the key was in use either. Revocation is therefore terminal:
     * undoing it is refused, and the answer is to issue a new key.
     */
    public function revokeDecision(ApiKey $key): ApiKeyDecision
    {
        if ($key->isRevoked()) {
            return ApiKeyDecision::noChange(sprintf(
                'Key %s is already revoked at %s; writing again would look like a new event '
                . 'in a table that cannot tell them apart.',
                (string) $key->id,
                $key->revokedAt?->format('c') ?? '—'
            ));
        }

        return ApiKeyDecision::allowed();
    }

    public function unrevokeDecision(ApiKey $key): ApiKeyDecision
    {
        return ApiKeyDecision::denied(
            ApiKeyDecision::REVOCATION_IS_TERMINAL,
            sprintf(
                'Un-revoking key %s would leave no trace that it was ever revoked: there is no '
                . 'history column and no last-used column. Issue a new key instead.',
                (string) $key->id
            )
        );
    }

    /**
     * Audit a row that already exists — the only way to find the ones written
     * before these rules.
     */
    public function auditDecision(ApiKey $key, \DateTimeImmutable $now): ApiKeyDecision
    {
        if ($key->revokedInTheFuture($now)) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::REVOKED_IN_THE_FUTURE,
                sprintf(
                    'Key %s carries a revocation dated %s, in the future: either a typo that '
                    . 'leaves the key live, or a scheduled death nobody will be watching.',
                    (string) $key->id,
                    $key->revokedAt?->format('c') ?? '—'
                )
            );
        }

        if (! $key->belongsToOrganization()) {
            return ApiKeyDecision::denied(
                ApiKeyDecision::NO_ORGANIZATION,
                sprintf('Key %s belongs to no organization.', (string) $key->id)
            );
        }

        return $this->useDecision($key, $now);
    }
}
