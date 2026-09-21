<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Services;

use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketScan;
use App\Modules\Tickets\Repositories\TicketRepository;
use App\Modules\Checkin\Domain\CheckinEvaluator;
use App\Modules\Sessions\Models\Session;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/**
 * Service for handling ticket scanning and check-in operations.
 * 
 * Provides atomic, idempotent ticket scanning with support for:
 * - Online verification against server database
 * - Offline bundle validation (pre-signed ticket lists)
 * - Concurrent scan detection (two checkers scanning same ticket)
 * - Replay attack prevention (same ticket scanned twice on same device)
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
     * @param int $ticketId The ticket ID to scan
     * @param int $sessionId The session/event ID
     * @param int|null $deviceId The checking device ID (null for online verification)
     * @return array{success: bool, message: string, ticket?: Ticket, scan?: TicketScan, reason?: string}
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

            // Verify ticket belongs to the session being checked
            if ($ticket->inventoryItem->session_id !== $sessionId) {
                return [
                    'success' => false,
                    'message' => 'Ticket does not belong to this session',
                    'reason' => 'WRONG_SESSION',
                ];
            }

            // Evaluate if check-in is allowed based on business rules
            $evaluation = $this->checkinEvaluator->evaluate($ticket, $deviceId);

            if (!$evaluation->allowed) {
                return [
                    'success' => false,
                    'message' => $evaluation->reason ?? 'Check-in not allowed',
                    'reason' => $evaluation->verdict ?? 'CHECKIN_DENIED',
                    'ticket' => $ticket,
                ];
            }

            // Check for duplicate scan on same device (replay attack prevention)
            if ($deviceId !== null) {
                $existingScan = TicketScan::query()
                    ->where('ticket_id', $ticketId)
                    ->where('device_id', $deviceId)
                    ->first();

                if ($existingScan) {
                    return [
                        'success' => false,
                        'message' => 'Ticket already scanned on this device',
                        'reason' => 'ALREADY_SCANNED_THIS_DEVICE',
                        'ticket' => $ticket,
                        'scan' => $existingScan,
                    ];
                }
            }

            // Record the scan
            $scan = TicketScan::create([
                'ticket_id' => $ticketId,
                'device_id' => $deviceId,
                'scanned_at' => now(),
                'location' => null, // Can be extended with GPS data
                'metadata' => [],
            ]);

            // Update ticket status on first successful check-in
            if ($ticket->status === 'issued') {
                $ticket->update(['status' => 'checked_in']);
            }

            return [
                'success' => true,
                'message' => 'Check-in successful',
                'ticket' => $ticket->fresh(),
                'scan' => $scan,
            ];
        });
    }

    /**
     * Verify if a ticket can be checked in without recording the scan.
     * Used for pre-scan validation or offline bundle verification.
     * 
     * @param Ticket $ticket
     * @param int $sessionId
     * @param int|null $deviceId
     * @return array{valid: bool, reason?: string}
     */
    public function canCheckin(Ticket $ticket, int $sessionId, ?int $deviceId = null): array
    {
        // Verify ticket belongs to the session
        if ($ticket->inventoryItem->session_id !== $sessionId) {
            return ['valid' => false, 'reason' => 'WRONG_SESSION'];
        }

        // Evaluate check-in eligibility
        $evaluation = $this->checkinEvaluator->evaluate($ticket, $deviceId);

        if (!$evaluation->allowed) {
            return ['valid' => false, 'reason' => $evaluation->verdict ?? 'CHECKIN_DENIED'];
        }

        return ['valid' => true];
    }

    /**
     * Process an offline bundle scan.
     * Validates ticket against pre-downloaded bundle hash.
     * 
     * @param string $ticketPublicId The ticket's public UUID
     * @param string $bundleHash The bundle hash the device has
     * @param int $deviceId The checking device ID
     * @return array{success: bool, message: string, reason?: string}
     */
    public function scanFromBundle(string $ticketPublicId, string $bundleHash, int $deviceId): array
    {
        // Find ticket by public ID
        $ticket = Ticket::query()
            ->where('public_id', $ticketPublicId)
            ->first();

        if (!$ticket) {
            return [
                'success' => false,
                'message' => 'Ticket not found',
                'reason' => 'TICKET_NOT_FOUND',
            ];
        }

        // Verify the bundle is valid for this device and session
        $bundleValid = $this->verifyBundleForDevice($bundleHash, $deviceId, $ticket->inventoryItem->session_id);

        if (!$bundleValid) {
            return [
                'success' => false,
                'message' => 'Invalid bundle for this device/session',
                'reason' => 'INVALID_BUNDLE',
            ];
        }

        // Proceed with normal scan
        return $this->scan($ticket->id, $ticket->inventoryItem->session_id, $deviceId);
    }

    /**
     * Verify that a bundle hash is valid for a specific device and session.
     * 
     * @param string $bundleHash
     * @param int $deviceId
     * @param int $sessionId
     * @return bool
     */
    private function verifyBundleForDevice(string $bundleHash, int $deviceId, int $sessionId): bool
    {
        // Check if bundle exists and is valid for this device
        $bundle = DB::table('offline_bundles')
            ->where('bundle_hash', $bundleHash)
            ->where('device_id', $deviceId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$bundle) {
            return false;
        }

        // Verify bundle covers this session
        return (int) $bundle->session_id === $sessionId;
    }

    /**
     * Get scan history for a ticket.
     * Useful for debugging disputed entries.
     * 
     * @param int $ticketId
     * @return array
     */
    public function getScanHistory(int $ticketId): array
    {
        return TicketScan::query()
            ->where('ticket_id', $ticketId)
            ->with('device')
            ->orderBy('scanned_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Handle concurrent scan scenario where two devices scan same ticket.
     * Returns which scan was first and rejects the second.
     * 
     * This is called when a scan fails due to unique constraint violation.
     * 
     * @param int $ticketId
     * @param int $deviceId
     * @return array
     */
    public function handleConcurrentScan(int $ticketId, int $deviceId): array
    {
        // Find the existing scan
        $existingScan = TicketScan::query()
            ->where('ticket_id', $ticketId)
            ->where('device_id', $deviceId)
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

        // If no scan found, it might be a race condition - retry
        return [
            'success' => false,
            'message' => 'Concurrent scan detected, please retry',
            'reason' => 'CONCURRENT_SCAN',
        ];
    }
}
