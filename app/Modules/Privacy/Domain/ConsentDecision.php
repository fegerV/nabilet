<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Domain;

/**
 * Whether processing is permitted for a purpose.
 *
 * The verdicts are separated because the remedies differ:
 *
 *   GRANTED           — go ahead.
 *   NO_RECORD         — nobody ever asked. Absence of a record is NOT consent;
 *                       this is the fail-closed default and the reason the
 *                       default exists.
 *   WITHDRAWN         — they asked, then took it back. Stop, and do not ask again
 *                       by the same channel.
 *   EXPIRED           — it lapsed by policy. Re-ask.
 *   NOT_FOR_PURPOSE   — they consented to something, but not to this. The most
 *                       common real-world failure: checking "does this person
 *                       have any consent?" instead of "do they consent to THIS?".
 *                       `consents.consent_type` exists precisely so marketing is
 *                       not analytics; treating a blanket lookup as permission
 *                       makes the column pointless.
 */
final class ConsentDecision
{
    public const GRANTED = 'granted';
    public const NO_RECORD = 'no_record';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';
    public const NOT_FOR_PURPOSE = 'not_for_purpose';

    private function __construct(
        public readonly string $verdict,
        public readonly bool $permitted,
        public readonly ?string $reason = null,
    ) {
    }

    public static function granted(): self
    {
        return new self(self::GRANTED, true);
    }

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isPermitted(): bool
    {
        return $this->permitted;
    }
}
