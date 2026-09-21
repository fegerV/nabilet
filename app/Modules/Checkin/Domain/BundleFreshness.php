<?php

declare(strict_types=1);

namespace App\Modules\Checkin\Domain;

/**
 * Whether an offline decision was made under an authority that was still valid.
 *
 * This is asked at RECONCILIATION, not at the door. The device has already let
 * someone in or turned them away; nothing here can change that. What it answers
 * is the question support asks a week later: "was this device working from a
 * current list, or from one that had gone stale?"
 *
 * It deliberately does NOT override CheckinEvaluator. A stale bundle and a valid
 * ticket still means the person was correctly admitted — the ticket's real status
 * is what decides, and that is the evaluator's job. Freshness only tells you how
 * much to trust the device's own reasoning, and it is recorded alongside the
 * outcome rather than folded into it.
 *
 * UNBOUNDED exists because `offline_bundles.expires_at` is nullable. A bundle
 * with no expiry is an offline admission authority with no end date: it can be
 * replayed indefinitely against a revoked list that never gets refreshed. There
 * is no safe way to call that "current", so it is reported on its own instead of
 * being quietly counted as either.
 */
final class BundleFreshness
{
    public const CURRENT = 'current';
    public const EXPIRED = 'expired';
    public const UNBOUNDED = 'unbounded';

    private function __construct(
        public readonly string $state,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function of(?\DateTimeImmutable $expiresAt, \DateTimeImmutable $at): self
    {
        if ($expiresAt === null) {
            return new self(
                self::UNBOUNDED,
                null,
                'the bundle has no expiry, so there is no point after which it stops '
                . 'being an authority; treat it as stale rather than as current.'
            );
        }

        if ($at > $expiresAt) {
            return new self(
                self::EXPIRED,
                $expiresAt,
                sprintf(
                    'the scan was made at %s, after the bundle expired at %s; the device '
                    . 'was deciding from a list that was no longer being maintained.',
                    $at->format(\DATE_ATOM),
                    $expiresAt->format(\DATE_ATOM)
                )
            );
        }

        return new self(self::CURRENT, $expiresAt);
    }

    public function isUsable(): bool
    {
        return $this->state === self::CURRENT;
    }
}
