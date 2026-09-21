<?php

declare(strict_types=1);

namespace App\Modules\Checkin\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A request to generate an offline bundle for one device.
 *
 * `sessionId` is nullable because `offline_bundles.session_id` is: a bundle may
 * cover a whole event. Everything downstream has to cope with that, and the one
 * place it bites is expiry — with no session there is no session end to derive
 * an expiry from, so the builder falls back to a fixed window.
 *
 * `sessionEndsAt` is passed in rather than looked up. This class must stay free
 * of the database, and the caller already has the session row; passing the value
 * keeps the rule testable without one.
 */
final class OfflineBundleRequest
{
    /** @param list<BundleTicket> $validTickets */
    /** @param list<BundleTicket> $revokedTickets */
    public function __construct(
        public readonly CheckinDevice $device,
        public readonly int $organizationId,
        public readonly int $eventId,
        public readonly ?int $sessionId,
        public readonly array $validTickets,
        public readonly array $revokedTickets,
        public readonly string $publicKeyFingerprint,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly ?\DateTimeImmutable $sessionEndsAt = null,
        public readonly int $schemaVersion = 1,
    ) {
        if ($organizationId <= 0 || $eventId <= 0) {
            throw new DomainRuleViolation(
                'A bundle request needs a positive organization id and event id.',
                'INVALID_BUNDLE_REQUEST'
            );
        }

        if ($sessionId !== null && $sessionId <= 0) {
            throw new DomainRuleViolation(
                sprintf('session_id must be null or positive, got %d.', $sessionId),
                'INVALID_BUNDLE_REQUEST'
            );
        }

        foreach ([$validTickets, $revokedTickets] as $list) {
            foreach ($list as $ticket) {
                if (! $ticket instanceof BundleTicket) {
                    throw new DomainRuleViolation(
                        'A bundle request takes BundleTicket instances only.',
                        'INVALID_BUNDLE_REQUEST'
                    );
                }
            }
        }

        if ($schemaVersion < 1) {
            throw new DomainRuleViolation(
                sprintf('schema_version must be at least 1, got %d.', $schemaVersion),
                'INVALID_BUNDLE_REQUEST'
            );
        }
    }

    /** @return list<BundleTicket> */
    public function allTickets(): array
    {
        return [...$this->validTickets, ...$this->revokedTickets];
    }
}
