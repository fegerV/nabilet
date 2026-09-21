<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Repositories;

use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketScan;
use Illuminate\Database\Eloquent\Collection;

class TicketRepository
{
    public function __construct(
        protected Ticket $model
    ) {}

    public function find(int $id, int $organizationId = null): ?Ticket
    {
        $query = $this->model->with(['order', 'inventoryItem.session', 'scans']);
        
        if ($organizationId) {
            $query->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }
        
        return $query->find($id);
    }

    public function findByPublicId(string $publicId, int $organizationId = null): ?Ticket
    {
        $query = $this->model->where('public_id', $publicId);
        
        if ($organizationId) {
            $query->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }
        
        return $query->with(['order', 'inventoryItem.session', 'scans'])->first();
    }

    public function findByOrder(int $orderId): Collection
    {
        return $this->model->where('order_id', $orderId)
            ->with(['inventoryItem.seat', 'scans'])
            ->get();
    }

    public function findBySession(int $sessionId, int $limit = 50): Collection
    {
        return $this->model->whereHas('order', function ($q) use ($sessionId) {
                $q->where('session_id', $sessionId);
            })
            ->with(['order.customer', 'inventoryItem.seat'])
            ->paginate($limit);
    }

    public function create(array $data): Ticket
    {
        return $this->model->create($data);
    }

    public function update(Ticket $ticket, array $data): Ticket
    {
        $ticket->update($data);
        return $ticket->fresh();
    }

    public function invalidate(Ticket $ticket, string $reason): Ticket
    {
        $ticket->update([
            'status' => 'invalidated',
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ]);
        return $ticket;
    }

    public function recordScan(Ticket $ticket, int $checkinDeviceId, string $location = null): TicketScan
    {
        return $ticket->scans()->create([
            'checkin_device_id' => $checkinDeviceId,
            'scanned_at' => now(),
            'location' => $location,
            'success' => true,
        ]);
    }

    public function getScanCount(Ticket $ticket): int
    {
        return $ticket->scans()->count();
    }

    public function findValidTicketsBySession(int $sessionId): Collection
    {
        return $this->model->whereHas('order', function ($q) use ($sessionId) {
                $q->where('session_id', $sessionId);
            })
            ->whereIn('status', ['issued', 'checked_in'])
            ->with(['order.customer', 'inventoryItem.seat'])
            ->get();
    }

    public function getCheckinStats(int $sessionId): array
    {
        $totalTickets = $this->model->whereHas('order', function ($q) use ($sessionId) {
            $q->where('session_id', $sessionId);
        })->whereIn('status', ['issued', 'checked_in'])->count();

        $checkedIn = $this->model->whereHas('order', function ($q) use ($sessionId) {
            $q->where('session_id', $sessionId);
        })->where('status', 'checked_in')->count();

        return [
            'total' => $totalTickets,
            'checked_in' => $checkedIn,
            'remaining' => $totalTickets - $checkedIn,
        ];
    }
}
