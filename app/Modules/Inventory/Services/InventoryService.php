<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Inventory\Repositories\InventoryItemRepository;
use App\Modules\Sessions\Models\Session;
use App\Modules\Venues\Halls\Models\HallSchemaVersion;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        protected InventoryItemRepository $repository
    ) {}

    public function generateFromSchema(HallSchemaVersion $schemaVersion, Session $session): int
    {
        return DB::transaction(function () use ($schemaVersion, $session) {
            // Clear existing inventory for this session
            InventoryItem::where('session_id', $session->id)->delete();

            $items = [];
            $payload = $schemaVersion->payload;

            // Generate seat inventory
            foreach ($payload['sectors'] ?? [] as $sector) {
                foreach ($sector['rows'] ?? [] as $row) {
                    foreach ($row['seats'] ?? [] as $seatData) {
                        $items[] = [
                            'session_id' => $session->id,
                            'type' => 'seat',
                            'seat_id' => $seatData['id'],
                            'sector_id' => $sector['id'] ?? null,
                            'quantity' => 1,
                            'available_quantity' => 1,
                            'price' => $seatData['price'] ?? $session->default_price,
                            'status' => 'available',
                            'metadata' => json_encode([
                                'sector_name' => $sector['name'] ?? null,
                                'row_label' => $row['label'] ?? null,
                                'seat_number' => $seatData['number'] ?? null,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }

            // Generate standing zone inventory
            foreach ($payload['standing_zones'] ?? [] as $zone) {
                $items[] = [
                    'session_id' => $session->id,
                    'type' => 'standing',
                    'standing_zone_id' => $zone['id'],
                    'quantity' => $zone['capacity'],
                    'available_quantity' => $zone['capacity'],
                    'price' => $zone['price'] ?? $session->default_price,
                    'status' => 'available',
                    'metadata' => json_encode([
                        'zone_name' => $zone['name'] ?? null,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (empty($items)) {
                return 0;
            }

            // Bulk insert
            $this->repository->bulkCreate($items);

            return count($items);
        });
    }

    public function getAvailableCount(Session $session): int
    {
        return $this->repository->getAvailableBySession($session->id)->sum('available_quantity');
    }

    public function getTotalCapacity(Session $session): int
    {
        return InventoryItem::where('session_id', $session->id)->sum('quantity');
    }

    public function getSoldCount(Session $session): int
    {
        return $this->repository->getSoldCount($session->id);
    }

    public function getHeldCount(Session $session): int
    {
        return $this->repository->getHeldCount($session->id);
    }

    public function reserveItem(InventoryItem $item, int $quantity = 1): InventoryItem
    {
        if ($item->available_quantity < $quantity) {
            throw new \RuntimeException('Insufficient available quantity');
        }

        return $this->repository->decrementQuantity($item, $quantity);
    }

    public function releaseItem(InventoryItem $item, int $quantity = 1): InventoryItem
    {
        return $this->repository->incrementQuantity($item, $quantity);
    }
}
