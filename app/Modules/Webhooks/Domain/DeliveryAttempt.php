<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One attempt to POST an event to a subscriber, and the outcome of it.
 *
 * `statusCode` is null when the request never got an answer — DNS failure, TLS
 * failure, connection refused, timeout. That is treated as retryable (see
 * RetryPolicy) and is the most common failure by far, because subscriber
 * endpoints are other people's infrastructure.
 *
 * `jitter` is injected rather than drawn from `random()` inside the policy. A
 * backoff that cannot be pinned to a value cannot be asserted on, and a test that
 * cannot state the expected delay will either be deleted or fudged. The caller
 * passes a number in [0, 1); production passes a random one.
 */
final class DeliveryAttempt
{
    public const DEFAULT_RETRY_LIMIT = 10;

    public function __construct(
        public readonly int $attempt,
        public readonly ?int $statusCode,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly int $retryLimit = self::DEFAULT_RETRY_LIMIT,
        public readonly float $jitter = 1.0,
    ) {
        if ($attempt < 1) {
            throw new DomainRuleViolation(
                sprintf('An attempt is numbered from 1, got %d.', $attempt),
                'INVALID_ATTEMPT'
            );
        }

        if ($retryLimit < 0) {
            throw new DomainRuleViolation(
                sprintf('A retry limit cannot be negative, got %d.', $retryLimit),
                'INVALID_RETRY_LIMIT'
            );
        }

        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new DomainRuleViolation(
                sprintf('Jitter must be within [0, 1], got %s.', $jitter),
                'INVALID_JITTER'
            );
        }
    }
}
