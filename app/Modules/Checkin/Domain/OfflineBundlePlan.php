<?php

declare(strict_types=1);

namespace App\Modules\Checkin\Domain;

/**
 * The bundle that should be written to `offline_bundles` — computed, not trusted.
 *
 * The counts are DERIVED from the ticket lists, never taken from the caller. The
 * schema cannot help here: `ticket_count` and `revoked_count` are plain
 * `INT UNSIGNED` columns with no CHECK tying them to `payload_json`. A mismatch
 * is invisible to MySQL and visible to everyone else — the device shows "1 200
 * tickets" while holding 1 198, and reconciliation starts from a wrong total.
 *
 * `expiresAt` is never null even though the column allows it. A null expiry is an
 * offline admission authority with no end date, which is the one thing this
 * design cannot tolerate: see OfflineBundleBuilder::expiryFor().
 */
final class OfflineBundlePlan
{
    /** @param array<string, mixed> $payload the canonical structure that was hashed */
    public function __construct(
        public readonly string $bundleHash,
        public readonly string $publicKeyFingerprint,
        public readonly int $schemaVersion,
        public readonly int $ticketCount,
        public readonly int $revokedCount,
        public readonly array $payload,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * The value for `payload_json`. Re-encoded from the same structure that was
     * hashed, so what the device receives is byte-for-byte what was signed over.
     */
    public function payloadJson(): string
    {
        return json_encode(
            $this->payload,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        );
    }

    public function totalCount(): int
    {
        return $this->ticketCount + $this->revokedCount;
    }
}
