<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Models\InventoryItem;
use Illuminate\Database\Eloquent\Collection;

class InventoryItemRepository
{
    public function __construct(
        protected InventoryItem $model
    ) {}

    public function find(int $id): ?InventoryItem
    {
        return $this->model->with(['session', 'seat', 'standingZone'])->find($id);
    }

    public function findBySession(int $sessionId, int $limit = 50): Collection
    {
        return $this->model->where('session_id', $sessionId)
            ->with(['seat', 'standingZone'])
            ->paginate($limit);
    }

    public function findBySessionAndStatus(int $sessionId, string $status, int $limit = 50): Collection
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
        return $this->model->where('session_id', $sessionId)
            ->sum('quantity') - $this->model->where('session_id', $sessionId)->sum('available_quantity');
    }

    public function getHeldCount(int $sessionId): int
    {
        // Count items with active holds
        return $this->model->where('session_id', $sessionId)
            ->whereHas('holds', function ($query) {
                $query->where('expires_at', '>', now());
            })
            ->count();
    }
}
