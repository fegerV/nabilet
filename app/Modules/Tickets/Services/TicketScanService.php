<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Modules\Tickets\Domain\CheckinEvaluator;
use Nabilet\Modules\Tickets\Domain\ScanMode;
use Nabilet\Modules\Tickets\Domain\ScanOutcome;
use Nabilet\Modules\Tickets\Domain\ScanRequest;
use Nabilet\Modules\Tickets\Domain\TicketSnapshot;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketScan;
use Nabilet\Modules\Tickets\Repositories\TicketRepository;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for handling ticket scanning and check-in operations.
 *
 * The decision itself belongs to the pure domain CheckinEvaluator, whose real
 * contract is evaluate(ScanRequest, ?TicketSnapshot): this service is the
 * adapter that builds those value objects from Eloquent models, persists the
 * scan row, and applies `resultingStatus` — the evaluator never writes.
 */
class TicketScanService
{
    public function __construct(
        private readonly TicketRepository $repository,
        private readonly CheckinEvaluator $checkinEvaluator
    ) {}

    /**
     * Scan a ticket and record the check-in.
     *
     * @return array{success: bool, message: string, result?: string, ticket?: Ticket, scan?: TicketScan, reason?: string}
     */
    public function scan(int $ticketId, int $sessionId, ?int $deviceId = null): array
    {
        return DB::transaction(function () use ($ticketId, $sessionId, $deviceId) {
            // Load ticket with lock to prevent concurrent modifications
            $ticket = Ticket::query()
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            if (!$ticket) {
                return [
                    'success' => false,
                    'message' => 'Ticket not found',
                    'reason' => 'TICKET_NOT_FOUND',
                ];
            }

            // tickets.session_id is a first-class column (spec schema); there is
            // no inventoryItem relation on Ticket and going through it produced
            // "Call to undefined relationship" errors at runtime.
            if ((int) $ticket->session_id !== $sessionId) {
                return [
                    'success' => false,
                    'message' => 'Ticket does not belong to this session',
                    'reason' => 'WRONG_SESSION',
                ];
            }

            $outcome = $this->checkinEvaluator->evaluate(
                new ScanRequest(
                    ticketPublicId: $ticket->public_id,
                    sessionId: $sessionId,
                    mode: ScanMode::ONLINE,
                    deviceId: $deviceId,
                ),
                $this->snapshot($ticket),
            );

            $scan = $this->repository->recordScan(
                $ticket,
                $deviceId,
                result: $outcome->result,
                mode: ScanMode::ONLINE,
            );

            if (!$outcome->admits) {
                return [
                    'success' => false,
                    'message' => $outcome->reason ?? 'Check-in not allowed',
                    'result' => $outcome->result,
                    'reason' => strtoupper($outcome->result),
                    'ticket' => $ticket,
                    'scan' => $scan,
                    'used_at' => $outcome->usedAt?->format(\DATE_ATOM),
                ];
            }

            // Admitted: issued -> used, exactly once (no used -> issued path in
            // the state machine; §32 re-scans return already_used above).
            if ($outcome->resultingStatus !== null && $ticket->status !== $outcome->resultingStatus) {
                $ticket->update([
                    'status' => $outcome->resultingStatus,
                    'used_at' => now(),
                ]);
            }

            return [
                'success' => true,
                'message' => 'Check-in successful',
                'result' => $outcome->result,
                'ticket' => $ticket->fresh(),
                'scan' => $scan,
            ];
        });
    }

    /**
     * Verify if a ticket can be checked in without recording the scan.
     *
     * @return array{valid: bool, reason?: string, result?: string}
     */
    public function canCheckin(Ticket $ticket, int $sessionId, ?int $deviceId = null): array
    {
        if ((int) $ticket->session_id !== $sessionId) {
            return ['valid' => false, 'reason' => 'WRONG_SESSION'];
        }

        $outcome = $this->checkinEvaluator->evaluate(
            new ScanRequest(
                ticketPublicId: $ticket->public_id,
                sessionId: $sessionId,
                mode: ScanMode::ONLINE,
                deviceId: $deviceId,
            ),
            $this->snapshot($ticket),
        );

        if (!$outcome->admits) {
            return [
                'valid' => false,
                'result' => $outcome->result,
                'reason' => strtoupper($outcome->result),
            ];
        }

        return ['valid' => true, 'result' => $outcome->result];
    }

    /**
     * Process an offline bundle scan (§44 reconciliation).
     *
     * The device already made its decision at the door; the server records what
     * happened and resolves conflicts by revocation — it cannot un-admit anyone.
     */
    public function scanFromBundle(
        string $ticketPublicId,
        string $bundleHash,
        int $deviceId,
        ?string $clientScanId = null,
        bool $deviceAdmitted = true,
        ?\DateTimeImmutable $scannedAt = null,
    ): array {
        return DB::transaction(function () use ($ticketPublicId, $bundleHash, $deviceId, $clientScanId, $deviceAdmitted, $scannedAt) {
            $ticket = Ticket::query()
                ->where('public_id', $ticketPublicId)
                ->lockForUpdate()
                ->first();

            if (!$ticket) {
                return [
                    'success' => false,
                    'message' => 'Ticket not found',
                    'reason' => 'TICKET_NOT_FOUND',
                ];
            }

            if (!$this->verifyBundleForDevice($bundleHash, $deviceId, (int) $ticket->session_id)) {
                return [
                    'success' => false,
                    'message' => 'Invalid bundle for this device/session',
                    'reason' => 'INVALID_BUNDLE',
                ];
            }

            // De-duplicate retried uploads via uq_ticket_scans_client
            // (device_id, client_scan_id): the same upload applied twice must
            // not become two admissions.
            if ($clientScanId !== null) {
                $existing = TicketScan::query()
                    ->where('device_id', $deviceId)
                    ->where('client_scan_id', $clientScanId)
                    ->first();

                if ($existing) {
                    return [
                        'success' => true,
                        'message' => 'Scan already recorded (idempotent replay)',
                        'result' => $existing->result,
                        'ticket' => $ticket,
                        'scan' => $existing,
                    ];
                }
            }

            $outcome = $this->checkinEvaluator->evaluate(
                new ScanRequest(
                    ticketPublicId: $ticket->public_id,
                    sessionId: (int) $ticket->session_id,
                    mode: ScanMode::OFFLINE_SYNC,
                    deviceId: $deviceId,
                    clientScanId: $clientScanId ?? (string) Str::uuid(),
                    scannedAt: $scannedAt,
                    deviceAdmitted: $deviceAdmitted,
                ),
                $this->snapshot($ticket),
            );

            $scan = $this->repository->recordScan(
                $ticket,
                $deviceId,
                result: $outcome->result,
                mode: ScanMode::OFFLINE_SYNC,
                clientScanId: $clientScanId,
                metadata: ['bundle_hash' => $bundleHash],
            );

            if ($outcome->result === ScanOutcome::CONFLICT_REVOKED) {
                // §44: person is inside, ticket was refunded/revoked/expired —
                // record the conflict honestly by revoking, never mark `used`.
                $ticket = $this->repository->revoke($ticket, (string) $outcome->reason);
            } elseif ($outcome->resultingStatus !== null && $ticket->status !== $outcome->resultingStatus) {
                $ticket->update([
                    'status' => $outcome->resultingStatus,
                    'used_at' => $scannedAt ? \Carbon\Carbon::instance($scannedAt) : now(),
                ]);
            }

            return [
                'success' => $outcome->admits || $outcome->result === ScanOutcome::CONFLICT_REVOKED,
                'message' => $outcome->reason ?? ('offline sync recorded: ' . $outcome->result),
                'result' => $outcome->result,
                'ticket' => $ticket instanceof Ticket ? $ticket->fresh() : $ticket,
                'scan' => $scan,
            ];
        });
    }

    /**
     * Verify that a bundle hash is valid for a specific device and session.
     */
    private function verifyBundleForDevice(string $bundleHash, int $deviceId, int $sessionId): bool
    {
        // offline_bundles keys the device column as `checkin_device_id`, and
        // `expires_at` is DATETIME(6) — comparing against a raw integer or
        // inventing a `device_id` column failed silently/with SQL errors.
        $bundle = DB::table('offline_bundles')
            ->where('bundle_hash', $bundleHash)
            ->where('checkin_device_id', $deviceId)
            ->where('session_id', $sessionId)
            ->where('expires_at', '>', now())
            ->first();

        return $bundle !== null;
    }

    /**
     * Get scan history for a ticket.
     */
    public function getScanHistory(int $ticketId): array
    {
        return TicketScan::query()
            ->where('ticket_id', $ticketId)
            ->orderBy('scanned_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Handle concurrent scan scenario where two devices scan same ticket.
     */
    public function handleConcurrentScan(int $ticketId, int $deviceId): array
    {
        $existingScan = TicketScan::query()
            ->where('ticket_id', $ticketId)
            ->where('device_id', $deviceId)
            ->orderBy('scanned_at')
            ->first();

        if ($existingScan) {
            return [
                'success' => false,
                'message' => 'Ticket was already scanned',
                'reason' => 'ALREADY_SCANNED',
                'first_scan_at' => $existingScan->scanned_at,
                'first_device_id' => $existingScan->device_id,
            ];
        }

        return [
            'success' => false,
            'message' => 'Concurrent scan detected, please retry',
            'reason' => 'CONCURRENT_SCAN',
        ];
    }

    /**
     * Build the immutable domain snapshot the evaluator decides on. Only the
     * fields the decision needs — deliberately no holder data (ТЗ §43/§44).
     */
    private function snapshot(Ticket $ticket): TicketSnapshot
    {
        $toImmutable = static fn ($dt): ?\DateTimeImmutable => $dt === null
            ? null
            : \DateTimeImmutable::createFromInterface($dt);

        return new TicketSnapshot(
            publicId: $ticket->public_id,
            status: $ticket->status ?? TicketStateMachine::ISSUED,
            sessionId: (int) $ticket->session_id,
            eventId: (int) $ticket->event_id,
            ticketNumber: (string) $ticket->ticket_number,
            usedAt: $toImmutable($ticket->used_at),
            revokedAt: $toImmutable($ticket->revoked_at),
            cancelledAt: $toImmutable($ticket->cancelled_at),
            refundedAt: $toImmutable($ticket->refunded_at),
            expiredAt: $toImmutable($ticket->expired_at),
            revokedReason: $ticket->revoked_reason,
        );
    }
}
