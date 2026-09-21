<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * The lifetime of a seat hold (ТЗ §24, §84).
 *
 * DEFAULT 10 MINUTES, CONFIGURABLE RANGE 5–30 — the spec states the range, so it
 * is enforced here rather than trusted from config. A TTL of 0 would let the
 * sweeper free a seat from under someone mid-checkout; a TTL of an hour would
 * pin inventory for a buyer who wandered off, which is a denial-of-sale against
 * everyone else.
 *
 * THE GRACE PERIOD IS PART OF THE WINDOW, NOT AN AFTERTHOUGHT.
 * `expires_at` is when the hold stops being *honoured*; the sweeper releases it
 * only at `expires_at + grace`. A buyer who hits "pay" at 09:59:59 must not have
 * the seat stolen by a job that runs at 10:00:00. The grace is configured
 * separately (NABILET_HOLD_GRACE, default 30s) precisely so the two moments can
 * be reasoned about independently.
 */
final class HoldWindow
{
    public const MIN_TTL_SECONDS = 300;
    public const MAX_TTL_SECONDS = 1800;
    public const DEFAULT_TTL_SECONDS = 600;
    public const DEFAULT_GRACE_SECONDS = 30;

    private function __construct(
        private readonly \DateTimeImmutable $expiresAt,
        private readonly int $graceSeconds,
    ) {
    }

    public static function openingAt(\DateTimeImmutable $now, ?int $ttlSeconds = null, int $graceSeconds = self::DEFAULT_GRACE_SECONDS): self
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;

        if ($ttl < self::MIN_TTL_SECONDS || $ttl > self::MAX_TTL_SECONDS) {
            throw new DomainRuleViolation(
                sprintf(
                    'Hold TTL must be between %d and %d seconds (5–30 minutes), got %d.',
                    self::MIN_TTL_SECONDS,
                    self::MAX_TTL_SECONDS,
                    $ttl
                ),
                'INVALID_HOLD_TTL'
            );
        }

        if ($graceSeconds < 0) {
            throw new DomainRuleViolation('Hold grace cannot be negative.', 'INVALID_HOLD_TTL');
        }

        return new self($now->modify(sprintf('+%d seconds', $ttl)), $graceSeconds);
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function graceSeconds(): int
    {
        return $this->graceSeconds;
    }

    /** The hold is no longer honoured — but the seat is not necessarily free yet. */
    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** The moment the sweeper may return the units to the pool. */
    public function releasableAt(): \DateTimeImmutable
    {
        return $this->expiresAt->modify(sprintf('+%d seconds', $this->graceSeconds));
    }

    public function isReleasableAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->releasableAt();
    }
}
