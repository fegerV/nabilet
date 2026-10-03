<?php

declare(strict_types=1);

namespace Nabilet\Core\Idempotency;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A stored idempotency row.
 *
 * `responseStatus === null` means the request is IN FLIGHT: the row exists (so a
 * duplicate cannot start) but the handler has not finished. That is what
 * `locked_at` is for, and it is the state the old code had no concept of — a
 * concurrent duplicate simply ran the handler a second time, which on a checkout
 * means charging twice.
 */
final class IdempotencyRecord
{
    public function __construct(
        public readonly string $scope,
        public readonly string $keyHash,
        public readonly string $requestHash,
        public readonly ?int $responseStatus = null,
        public readonly ?string $responseBody = null,
        public readonly ?\DateTimeImmutable $lockedAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($responseStatus !== null && ($responseStatus < 100 || $responseStatus > 599)) {
            throw new DomainRuleViolation(
                sprintf('Impossible HTTP status stored: %d.', $responseStatus),
                'INVALID_IDEMPOTENCY_RECORD'
            );
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            scope: (string) ($row['scope'] ?? ''),
            keyHash: (string) ($row['key_hash'] ?? ''),
            requestHash: (string) ($row['request_hash'] ?? ''),
            responseStatus: isset($row['response_status']) ? (int) $row['response_status'] : null,
            responseBody: isset($row['response_body']) ? (string) $row['response_body'] : null,
            lockedAt: self::date($row['locked_at'] ?? null),
            expiresAt: self::date($row['expires_at'] ?? null),
            createdAt: self::date($row['created_at'] ?? null),
        );
    }

    public function isInFlight(): bool
    {
        return $this->responseStatus === null;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now > $this->expiresAt;
    }

    public function matchesRequest(string $requestHash): bool
    {
        return hash_equals($this->requestHash, $requestHash);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
