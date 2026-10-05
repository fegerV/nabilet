<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Repositories;

use Nabilet\Modules\Inventory\Models\InventoryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InventoryItemRepository
{
    public function __construct(
        protected InventoryItem $model
    ) {}

    public function find(int $id): ?InventoryItem
    {
        return $this->model->with(['session', 'seat', 'standingZone'])->find($id);
    }

    public function findBySession(int $sessionId, int $limit = 50): LengthAwarePaginator
    {
        return $this->model->where('session_id', $sessionId)
            ->with(['seat', 'standingZone'])
            ->paginate($limit);
    }

    public function findBySessionAndStatus(int $sessionId, string $status, int $limit = 50): LengthAwarePaginator
    {
        return $this->model->where('session_id', $sessionId)
            ->where('status', $status)
            ->paginate($limit);
    }

    public function getAvailableBySession(int $sessionId): Collection
    {
        return $this->model->where('session_id', $sessionId)
            ->where('available_quantity', '>', 0)
            ->where('status', 'available')
            ->with(['seat', 'standingZone'])
            ->get();
    }

    public function create(array $data): InventoryItem
    {
        return $this->model->create($data);
    }

    public function update(InventoryItem $item, array $data): InventoryItem
    {
        $item->update($data);
        return $item->fresh();
    }

    public function decrementQuantity(InventoryItem $item, int $amount = 1): InventoryItem
    {
        $item->decrement('available_quantity', $amount);
        
        if ($item->available_quantity === 0) {
            $item->update(['status' => 'sold_out']);
        }
        
        return $item->fresh();
    }

    public function incrementQuantity(InventoryItem $item, int $amount = 1): InventoryItem
    {
        $item->increment('available_quantity', $amount);
        
        if ($item->available_quantity > 0 && $item->status === 'sold_out') {
            $item->update(['status' => 'available']);
        }
        
        return $item->fresh();
    }

    public function bulkCreate(array $items): bool
    {
        return $this->model->insert($items);
    }

    public function getSoldCount(int $sessionId): int
    {
        $inventory = $this->model->newQuery()->where('session_id', $sessionId);
        $capacity = (int) (clone $inventory)->sum('capacity');
        $available = (int) (clone $inventory)->sum('available_quantity');

        // Holds also decrement available_quantity. Subtract open holds so this
        // reports sold units rather than sold + temporarily reserved units.
        $held = (int) DB::table('seat_holds')
            ->where('session_id', $sessionId)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->sum('quantity');

        return max(0, $capacity - $available - $held);
    }

    public function getHeldCount(int $sessionId): int
    {
        // Count inventory positions with a still-unexpired, unsettled hold.
        return $this->model->newQuery()
            ->where('session_id', $sessionId)
            ->whereHas('holds', function ($query): void {
                $query->where('expires_at', '>', now())
                    ->whereNull('released_at')
                    ->whereNull('converted_at');
            })
            ->count();
    }
}
