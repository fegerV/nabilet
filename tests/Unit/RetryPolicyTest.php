<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Webhooks\Domain\DeliveryAttempt;
use Nabilet\Modules\Webhooks\Domain\DeliveryOutcome;
use Nabilet\Modules\Webhooks\Domain\RetryPolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * When a failed webhook is worth another go (ТЗ §80).
 *
 * The behaviour under test is mostly about NOT retrying. A webhook system that
 * retries everything turns its subscribers' outages into load on those same
 * subscribers, and a `400` retried ten times is ten requests that were never going
 * to succeed.
 */
final class RetryPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-20 12:00:00');
    }

    /** @param list<mixed> $override */
    private function attempt(?int $statusCode, int $attempt = 1, int $retryLimit = 10, float $jitter = 1.0): DeliveryAttempt
    {
        return new DeliveryAttempt($attempt, $statusCode, $this->now, $retryLimit, $jitter);
    }

    // ── success ──────────────────────────────────────────────────────────────

    public function testA2xxIsDelivered(): void
    {
        foreach ([200, 201, 204, 299] as $code) {
            $outcome = (new RetryPolicy())->evaluate($this->attempt($code));

            $this->assertTrue($outcome->isDelivered(), sprintf('%d should count as delivered', $code));
            $this->assertNull($outcome->nextRetryAt());
        }
    }

    // ── never retry a 4xx ────────────────────────────────────────────────────

    /** The most important rule here: the same answer forever, so stop asking. */
    public function testAClientErrorIsNotRetried(): void
    {
        foreach ([400, 401, 403, 404, 410, 422] as $code) {
            $outcome = (new RetryPolicy())->evaluate($this->attempt($code));

            $this->assertTrue($outcome->isAbandoned(), sprintf('%d must not be retried', $code));
            $this->assertSame(DeliveryOutcome::REASON_PERMANENT_FAILURE, $outcome->reason());
        }
    }

    /** Following a redirect would POST a signed payload to an arbitrary host. */
    public function testARedirectIsNotFollowed(): void
    {
        $outcome = (new RetryPolicy())->evaluate($this->attempt(301));

        $this->assertTrue($outcome->isAbandoned());
        $this->assertSame(DeliveryOutcome::REASON_PERMANENT_FAILURE, $outcome->reason());
    }

    // ── do retry these ───────────────────────────────────────────────────────

    public function testAServerErrorIsRetried(): void
    {
        foreach ([500, 502, 503, 599] as $code) {
            $outcome = (new RetryPolicy())->evaluate($this->attempt($code));

            $this->assertTrue($outcome->shouldRetry(), sprintf('%d should be retried', $code));
        }
    }

    /** A null status means there was no answer at all — the common case. */
    public function testATransportFailureIsRetried(): void
    {
        $outcome = (new RetryPolicy())->evaluate($this->attempt(null));

        $this->assertTrue($outcome->shouldRetry());
    }

    /** These three explicitly say "later". */
    public function testStatusesThatAskUsToComeBackAreRetried(): void
    {
        foreach (RetryPolicy::RETRYABLE_CLIENT_STATUSES as $code) {
            $outcome = (new RetryPolicy())->evaluate($this->attempt($code));

            $this->assertTrue($outcome->shouldRetry(), sprintf('%d should be retried', $code));
        }
    }

    // ── the backoff curve ────────────────────────────────────────────────────

    /** jitter = 1.0 gives the full computed delay. */
    public function testTheFirstRetryWaitsTheBaseDelay(): void
    {
        $outcome = (new RetryPolicy())->evaluate($this->attempt(500, 1, 10, 1.0));

        $this->assertSame(
            $this->now->modify('+60 seconds')->format('Y-m-d H:i:s'),
            $outcome->nextRetryAt()->format('Y-m-d H:i:s')
        );
    }

    public function testTheDelayDoublesEachAttempt(): void
    {
        $policy = new RetryPolicy();

        $second = $policy->evaluate($this->attempt(500, 2, 10, 1.0));
        $this->assertSame(120, $policy->delaySeconds($this->attempt(500, 2, 10, 1.0)));

        $third = $policy->evaluate($this->attempt(500, 3, 10, 1.0));
        $this->assertSame(240, $policy->delaySeconds($this->attempt(500, 3, 10, 1.0)));

        $this->assertNotNull($second->nextRetryAt());
        $this->assertNotNull($third->nextRetryAt());
    }

    /** A long outage must not schedule a retry days out. */
    public function testTheDelayIsCapped(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(RetryPolicy::MAX_DELAY_SECONDS, $policy->delaySeconds($this->attempt(500, 12, 20, 1.0)));
        $this->assertSame(RetryPolicy::MAX_DELAY_SECONDS, $policy->delaySeconds($this->attempt(500, 40, 50, 1.0)));
    }

    /** The floor keeps jitter from scheduling an "exponential" retry immediately. */
    public function testJitterNeverDropsBelowHalfTheDelay(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(30, $policy->delaySeconds($this->attempt(500, 1, 10, 0.0)), 'jitter 0 -> 50% of 60');
        $this->assertSame(60, $policy->delaySeconds($this->attempt(500, 1, 10, 1.0)), 'jitter 1 -> 100% of 60');
        $this->assertSame(45, $policy->delaySeconds($this->attempt(500, 1, 10, 0.5)), 'jitter 0.5 -> 75% of 60');
    }

    // ── honouring the configured limit ───────────────────────────────────────

    /** It might work on the eleventh try. We said ten. */
    public function testTheRetryLimitIsHonoured(): void
    {
        $outcome = (new RetryPolicy())->evaluate($this->attempt(500, 10, 10));

        $this->assertTrue($outcome->isAbandoned());
        $this->assertSame(DeliveryOutcome::REASON_RETRY_LIMIT_REACHED, $outcome->reason());
        $this->assertNull($outcome->nextRetryAt());
    }

    public function testTheLastAllowedAttemptStillRetries(): void
    {
        $outcome = (new RetryPolicy())->evaluate($this->attempt(500, 9, 10));

        $this->assertTrue($outcome->shouldRetry());
    }

    /** The limit is checked before the delay, so a limit of 0 means "never retry". */
    public function testAZeroRetryLimitMeansNoRetries(): void
    {
        $outcome = (new RetryPolicy())->evaluate($this->attempt(500, 1, 0));

        $this->assertSame(DeliveryOutcome::REASON_RETRY_LIMIT_REACHED, $outcome->reason());
    }

    /** Kept distinct: "wrong endpoint" vs "down a long time". */
    public function testPermanentFailureAndLimitReachedAreDifferentOutcomes(): void
    {
        $policy = new RetryPolicy();

        $wrong = $policy->evaluate($this->attempt(404, 1, 10));
        $down = $policy->evaluate($this->attempt(503, 10, 10));

        $this->assertTrue($wrong->isAbandoned());
        $this->assertTrue($down->isAbandoned());
        $this->assertNotSame($wrong->reason(), $down->reason());
    }

    // ── invariants ───────────────────────────────────────────────────────────

    public function testAttemptsAreNumberedFromOne(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new DeliveryAttempt(0, 500, $this->now)
        );
    }

    public function testJitterOutsideTheUnitIntervalIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new DeliveryAttempt(1, 500, $this->now, 10, 1.5)
        );

        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new DeliveryAttempt(1, 500, $this->now, 10, -0.1)
        );
    }

    public function testTheDefaultRetryLimitMatchesTheSchema(): void
    {
        $this->assertSame(10, DeliveryAttempt::DEFAULT_RETRY_LIMIT, 'webhooks.retry_limit DEFAULT 10');
    }
}
