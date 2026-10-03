<?php

declare(strict_types=1);

namespace Nabilet\Core\Idempotency;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * The rules for replaying an idempotent request (ТЗ §28, §76).
 *
 * PURE: it is given the key, the scope, the request fingerprint and whatever row
 * already exists, and it returns a decision. No database, no HTTP. That is what
 * makes the dangerous cases testable — and they are the whole point:
 *
 *   - a duplicate arriving WHILE the first request is still running
 *   - the same key reused with a different payload
 *   - a replay after the record has expired
 *   - a 5xx response, which must stay retryable
 *
 * Two decisions are worth arguing for:
 *
 * IN_FLIGHT REFUSES rather than re-executing. A duplicate that arrives while the
 * handler is running is the exact scenario the key exists for. Executing again
 * "just in case" is what double-charges a customer; refusing with 409 lets the
 * client retry and get the stored response on the next attempt.
 *
 * A 5xx IS NOT RECORDED. Caching a failure would turn a transient outage into a
 * permanent one: every retry would keep returning the same 502 forever.
 */
final class IdempotencyPolicy
{
    public const MAX_KEY_LENGTH = 191;
    public const TTL_HOURS = 24;

    /** Below this status nothing is stored, so failures stay retryable. */
    private const RETRYABLE_FROM = 500;

    public function decide(
        ?string $key,
        string $scope,
        string $requestHash,
        ?IdempotencyRecord $existing,
        ?\DateTimeImmutable $now = null,
    ): string {
        if ($key === null || $key === '') {
            return IdempotencyDecision::SKIP;
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new DomainRuleViolation(
                sprintf('Idempotency key must not exceed %d characters.', self::MAX_KEY_LENGTH),
                'IDEMPOTENCY_KEY_TOO_LONG'
            );
        }

        if ($existing === null) {
            return IdempotencyDecision::PROCEED;
        }

        $now ??= new \DateTimeImmutable('now');

        // An expired record is not evidence of anything: the client is entitled to
        // reuse the key. Treating it as a replay would hand back a stale response.
        if ($existing->isExpired($now)) {
            return IdempotencyDecision::PROCEED;
        }

        if (! $existing->matchesRequest($requestHash)) {
            return IdempotencyDecision::CONFLICT;
        }

        if ($existing->isInFlight()) {
            return IdempotencyDecision::IN_FLIGHT;
        }

        return IdempotencyDecision::REPLAY;
    }

    public function shouldRecord(int $status): bool
    {
        return $status < self::RETRYABLE_FROM;
    }

    public function keyHash(string $key): string
    {
        return hash('sha256', $key);
    }

    public function requestHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public function expiresAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('+%d hours', self::TTL_HOURS));
    }
}
