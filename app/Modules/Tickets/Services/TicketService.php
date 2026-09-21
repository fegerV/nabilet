<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Services;

use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Repositories\TicketRepository;
use App\Modules\Tickets\Domain\TicketIssuance;
use App\Modules\Tickets\Domain\CheckinEvaluator;
use App\Modules\Orders\Models\Order;
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
        // Generate QR code data (could be a signed JWT or URL)
        $data = [
            'order_id' => $order->public_id,
            'item_id' => $item->id,
            'timestamp' => time(),
        ];

        return json_encode($data);
    }
}
