<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Repositories\TicketRepository;
use Nabilet\Modules\Tickets\Domain\TicketIssuance;
use Nabilet\Modules\Tickets\Domain\CheckinEvaluator;
use Nabilet\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    public function __construct(
        protected TicketRepository $repository,
        protected TicketIssuance $issuance,
        protected CheckinEvaluator $checkinEvaluator
    ) {}

    public function issueTicketsForOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            if ($order->status !== 'completed') {
                throw new \RuntimeException('Can only issue tickets for completed orders');
            }

            $tickets = [];

            foreach ($order->items as $item) {
                for ($i = 0; $i < $item->quantity; $i++) {
                    $ticket = $this->repository->create([
                        'public_id' => Str::uuid()->toString(),
                        'order_id' => $order->id,
                        'inventory_item_id' => $item->inventory_item_id,
                        'status' => 'issued',
                        'barcode_data' => $this->generateBarcodeData($order, $item),
                        'qr_code' => $this->generateQrCode($order, $item),
                        'issued_at' => now(),
                    ]);

                    $tickets[] = $ticket;
                }
            }

            return $tickets;
        });
    }

    public function findTicket(int $ticketId, int $organizationId = null): ?Ticket
    {
        return $this->repository->find($ticketId, $organizationId);
    }

    public function findByPublicId(string $publicId, int $organizationId = null): ?Ticket
    {
        return $this->repository->findByPublicId($publicId, $organizationId);
    }

    public function checkInTicket(Ticket $ticket, int $checkinDeviceId, string $location = null): array
    {
        return DB::transaction(function () use ($ticket, $checkinDeviceId, $location) {
            // Evaluate if check-in is allowed
            $evaluation = $this->checkinEvaluator->evaluate($ticket, $checkinDeviceId, $location);

            if (!$evaluation->allowed) {
                return [
                    'success' => false,
                    'reason' => $evaluation->reason,
                    'ticket' => $ticket,
                ];
            }

            // Record scan
            $scan = $this->repository->recordScan($ticket, $checkinDeviceId, $location);

            // Update ticket status on first successful check-in
            if ($ticket->status === 'issued') {
                $ticket->update(['status' => 'checked_in']);
            }

            return [
                'success' => true,
                'scan' => $scan,
                'ticket' => $ticket->fresh(),
            ];
        });
    }

    public function invalidateTicket(Ticket $ticket, string $reason): Ticket
    {
        if ($ticket->status === 'invalidated') {
            throw new \RuntimeException('Ticket is already invalidated');
        }

        return $this->repository->invalidate($ticket, $reason);
    }

    public function getTicketsByOrder(int $orderId): array
    {
        return $this->repository->findByOrder($orderId)->toArray();
    }

    public function getTicketsBySession(int $sessionId, int $limit = 50)
    {
        return $this->repository->findBySession($sessionId, $limit);
    }

    public function getCheckinStats(int $sessionId): array
    {
        return $this->repository->getCheckinStats($sessionId);
    }

    protected function generateBarcodeData(Order $order, $item): string
    {
        // Generate unique barcode data
        return sprintf(
            'ORD-%s-ITEM-%d',
            $order->public_id,
            $item->id
        );
    }

    protected function generateQrCode(Order $order, $item): string
    {
        // Generate QR code data with HMAC-SHA256 signature for security
        // This prevents ticket forgery - the signature must match to be valid
        $ticketData = [
            'order_id' => $order->public_id,
            'item_id' => $item->id,
            'timestamp' => time(),
        ];

        // Get the secret key for signing (use APP_KEY or a dedicated QR_SECRET)
        $secret = config('app.key') ?? config('tickets.qr_secret');
        
        if (empty($secret)) {
            throw new \RuntimeException('QR code signing key not configured. Set APP_KEY or tickets.qr_secret');
        }

        // Create the payload JSON
        $payload = json_encode($ticketData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($payload === false) {
            throw new \RuntimeException('Failed to encode ticket data for QR code');
        }

        // Generate HMAC-SHA256 signature
        $signature = hash_hmac('sha256', $payload, $secret);

        // Return signed payload: base64(payload.signature)
        // This format is compact and can be easily decoded by the checker app
        $signedData = base64_encode($payload . '.' . $signature);

        return $signedData;
    }

    /**
     * Verify QR code signature to prevent forgery.
     * 
     * @param string $qrCodeData The QR code data to verify
     * @return array{valid: bool, data?: array, reason?: string}
     */
    public function verifyQrCodeSignature(string $qrCodeData): array
    {
        try {
            // Decode the base64 data
            $decoded = base64_decode($qrCodeData, true);
            
            if ($decoded === false) {
                return ['valid' => false, 'reason' => 'INVALID_BASE64'];
            }

            // Split payload and signature
            $parts = explode('.', $decoded);
            
            if (count($parts) !== 2) {
                return ['valid' => false, 'reason' => 'INVALID_FORMAT'];
            }

            [$payload, $providedSignature] = $parts;

            // Get the secret key
            $secret = config('app.key') ?? config('tickets.qr_secret');
            
            if (empty($secret)) {
                return ['valid' => false, 'reason' => 'SIGNING_KEY_NOT_CONFIGURED'];
            }

            // Verify the signature
            $expectedSignature = hash_hmac('sha256', $payload, $secret);
            
            if (!hash_equals($expectedSignature, $providedSignature)) {
                return ['valid' => false, 'reason' => 'SIGNATURE_MISMATCH'];
            }

            // Decode the payload
            $data = json_decode($payload, true);
            
            if ($data === null) {
                return ['valid' => false, 'reason' => 'INVALID_PAYLOAD_JSON'];
            }

            return ['valid' => true, 'data' => $data];
        } catch (\Exception $e) {
            return ['valid' => false, 'reason' => 'VERIFICATION_ERROR'];
        }
    }
}
