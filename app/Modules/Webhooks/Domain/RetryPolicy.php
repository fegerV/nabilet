<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

/**
 * When a failed webhook delivery is worth trying again (ТЗ §80).
 *
 * THE RULE THAT MATTERS MOST: a 4xx IS NOT RETRIED.
 *   `422`, `400`, `401`, `404` mean the subscriber's endpoint rejects this payload
 *   or does not exist. Trying again in a minute produces the same answer, forever,
 *   while consuming a worker slot and hammering somebody else's server. The only
 *   4xx worth another go are the ones that explicitly say "later": `408` (they
 *   timed out), `425` (too early), `429` (rate limited).
 *
 *   Retrying a `400` is the single most common way a webhook system becomes an
 *   unwitting DoS engine against its own customers.
 *
 * BACKOFF IS EXPONENTIAL, CAPPED, AND JITTERED WITH A FLOOR.
 *   `base * 2^(attempt-1)`, capped at MAX_DELAY so a long outage cannot schedule a
 *   retry three days out. The jitter keeps every subscriber that failed at the same
 *   second from retrying at the same second; the floor (50% of the computed delay)
 *   keeps jitter from scheduling an "exponential" retry for almost immediately,
 *   which would defeat the entire point of backing off.
 *
 * THE ATTEMPT COUNTER IS THE CONTRACT. `webhook_deliveries.attempt` starts at 1 and
 * `webhooks.retry_limit` defaults to 10. Once attempt N has failed and
 * N >= retry_limit, we stop — even though it might have worked on the eleventh
 * try. Silently exceeding a configured limit is worse than honouring it and
 * reporting the failure.
 */
final class RetryPolicy
{
    public const BASE_DELAY_SECONDS = 60;
    public const MAX_DELAY_SECONDS = 3600;

    /** Statuses that explicitly ask us to come back later. */
    public const RETRYABLE_CLIENT_STATUSES = [408, 425, 429];

    public function evaluate(DeliveryAttempt $attempt): DeliveryOutcome
    {
        if ($this->isSuccess($attempt->statusCode)) {
            return DeliveryOutcome::delivered();
        }

        if (! $this->isRetryable($attempt->statusCode)) {
            return DeliveryOutcome::permanentFailure();
        }

        if ($attempt->attempt >= $attempt->retryLimit) {
            return DeliveryOutcome::retryLimitReached();
        }

        return DeliveryOutcome::retryAt(
            $attempt->occurredAt->modify(sprintf('+%d seconds', $this->delaySeconds($attempt)))
        );
    }

    public function isSuccess(?int $statusCode): bool
    {
        return $statusCode !== null && $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * Null (no response at all), an explicit "later", or a server-side failure.
     *
     * 3xx is deliberately excluded: following a redirect would POST the signed
     * payload to whatever host the subscriber names, which turns a misconfigured
     * endpoint into a data-egress path.
     */
    public function isRetryable(?int $statusCode): bool
    {
        if ($statusCode === null) {
            return true;
        }

        if (in_array($statusCode, self::RETRYABLE_CLIENT_STATUSES, true)) {
            return true;
        }

        return $statusCode >= 500 && $statusCode < 600;
    }

    /** Seconds to wait, for deterministic tests and observable scheduling alike. */
    public function delaySeconds(DeliveryAttempt $attempt): int
    {
        $exponent = min($attempt->attempt - 1, 20); // guard against overflow
        $uncapped = self::BASE_DELAY_SECONDS * (2 ** $exponent);
        $capped = (int) min($uncapped, self::MAX_DELAY_SECONDS);

        // 50%..100% of the capped delay.
        return (int) round($capped * (0.5 + 0.5 * $attempt->jitter));
    }
}
