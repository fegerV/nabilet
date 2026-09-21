<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Domain;

/**
 * Whether a privacy request may move to a given state.
 *
 * The verdicts are separated because they mean different things to the person
 * handling the queue:
 *
 *   ALLOWED    — go ahead.
 *   TERMINAL   — closed; reopening would silently restart a statutory clock.
 *   BACKWARDS  — the requested move runs against the lifecycle.
 *   NO_CHANGE  — already there; do not write an UPDATE that only bumps a row.
 */
final class PrivacyDecision
{
    public const ALLOWED = 'allowed';
    public const TERMINAL = 'terminal';
    public const BACKWARDS = 'backwards';
    public const NO_CHANGE = 'no_change';

    private function __construct(
        public readonly string $verdict,
        public readonly bool $allowed,
        public readonly ?string $reason = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(self::ALLOWED, true);
    }

    public static function noChange(string $reason): self
    {
        return new self(self::NO_CHANGE, true, $reason);
    }

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /** True when there is genuinely something to write. */
    public function requiresWrite(): bool
    {
        return $this->verdict === self::ALLOWED;
    }
}
