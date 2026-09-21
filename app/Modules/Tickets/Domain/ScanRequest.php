<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One scan attempt arriving from a device.
 *
 * `clientScanId` is a UUID the DEVICE generates. It exists because a phone at a
 * crowded door will retry — the operator taps again when nothing seems to happen,
 * and the upload is retried when the network is bad. The unique key
 * `uq_ticket_scans_client (device_id, client_scan_id)` then collapses the retries
 * into one row, so "the operator tapped twice" cannot become "two people were
 * admitted on one ticket".
 *
 * Without it, idempotency would have to be inferred from (ticket_id, scanned_at)
 * — and two genuine scans one second apart are indistinguishable from one scan
 * uploaded twice.
 */
final class ScanRequest
{
    public function __construct(
        public readonly string $ticketPublicId,
        public readonly int $sessionId,
        public readonly string $mode = ScanMode::ONLINE,
        public readonly ?int $deviceId = null,
        public readonly ?string $clientScanId = null,
        public readonly ?\DateTimeImmutable $scannedAt = null,
        public readonly bool $deviceAdmitted = false,
    ) {
        if ($ticketPublicId === '') {
            throw new DomainRuleViolation('A scan request must identify a ticket.', 'INVALID_SCAN');
        }

        if (! in_array($mode, ScanMode::all(), true)) {
            throw new DomainRuleViolation(
                sprintf('Unknown scan mode "%s".', $mode),
                'INVALID_SCAN_MODE'
            );
        }

        // Offline sync without a client_scan_id cannot be de-duplicated, which
        // means a retried upload is indistinguishable from a second admission.
        if ($mode === ScanMode::OFFLINE_SYNC && $clientScanId === null) {
            throw new DomainRuleViolation(
                'An offline sync must carry a client_scan_id, otherwise a retried upload '
                . 'looks exactly like a second person being admitted.',
                'MISSING_CLIENT_SCAN_ID'
            );
        }
    }

    public function isOfflineSync(): bool
    {
        return $this->mode === ScanMode::OFFLINE_SYNC;
    }
}
