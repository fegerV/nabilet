<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Domain;

/**
 * What to do after an attempt: deliver it, retry it later, or stop.
 *
 * Four outcomes, not two. "Failed" is useless as an answer because the three ways
 * of failing demand three different actions:
 *
 *   delivered          mark delivered_at, stop
 *   retry_scheduled    leave next_retry_at set, the sweeper will pick it up
 *   permanent_failure  stop now — retrying cannot possibly succeed
 *   retry_limit_reached stop now — it might succeed, but we said we would try N
 *                      times and we have; continuing is a promise broken silently
 *
 * The last two are kept apart on purpose: one is "this endpoint is wrong" and the
 * other is "this endpoint is down for a long time". Support needs to know which.
 */
final class DeliveryOutcome
{
    public const REASON_DELIVERED = 'delivered';
    public const REASON_RETRY_SCHEDULED = 'retry_scheduled';
    public const REASON_PERMANENT_FAILURE = 'permanent_failure';
    public const REASON_RETRY_LIMIT_REACHED = 'retry_limit_reached';

    private function __construct(
        private readonly string $reason,
        private readonly ?\DateTimeImmutable $nextRetryAt,
    ) {
    }

    public static function delivered(): self
    {
        return new self(self::REASON_DELIVERED, null);
    }

    public static function retryAt(\DateTimeImmutable $when): self
    {
        return new self(self::REASON_RETRY_SCHEDULED, $when);
    }

    public static function permanentFailure(): self
    {
        return new self(self::REASON_PERMANENT_FAILURE, null);
    }

    public static function retryLimitReached(): self
    {
        return new self(self::REASON_RETRY_LIMIT_REACHED, null);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isDelivered(): bool
    {
        return $this->reason === self::REASON_DELIVERED;
    }

    public function shouldRetry(): bool
    {
        return $this->reason === self::REASON_RETRY_SCHEDULED;
    }

    /** Stopped for good, either because it cannot work or because we promised N. */
    public function isAbandoned(): bool
    {
        return $this->reason === self::REASON_PERMANENT_FAILURE
            || $this->reason === self::REASON_RETRY_LIMIT_REACHED;
    }

    public function nextRetryAt(): ?\DateTimeImmutable
    {
        return $this->nextRetryAt;
    }
}
