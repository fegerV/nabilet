<?php

declare(strict_types=1);

namespace App\Modules\Checkin\Domain;

/**
 * Whether a bundle may be generated, and if not, why.
 *
 * A decision object rather than an exception because "may this device sync?" is a
 * question the API and the UI ask before doing anything. Throwing would make the
 * caller catch an exception to answer a question it is entitled to ask.
 *
 * Each refusal is a different operational event and support needs to tell them
 * apart:
 *
 *   DEVICE_NOT_ACTIVE    — the device was disabled; someone must re-enable it.
 *   MISSING_PUBLIC_KEY   — a configuration gap in the issuer, not the device.
 *   NO_CONTENT           — nothing to sync. Refusing is the point: see below.
 *   CONTRADICTORY_TICKET — a data bug upstream; the bundle would be incoherent.
 *   WRONG_SESSION        — a query scoping bug; the bundle would admit into the
 *                          wrong session.
 */
final class BundleDecision
{
    public const ALLOWED = 'allowed';
    public const DEVICE_NOT_ACTIVE = 'device_not_active';
    public const MISSING_PUBLIC_KEY = 'missing_public_key';
    public const NO_CONTENT = 'no_content';
    public const CONTRADICTORY_TICKET = 'contradictory_ticket';
    public const WRONG_SESSION = 'wrong_session';

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

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
