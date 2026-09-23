<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Services;

use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Repositories\InventoryItemRepository;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Halls\Models\HallSchemaVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $payload = $schemaVersion->schema_json ?? [];

                // Generate seat inventory
                foreach ($payload['sectors'] ?? [] as $sector) {
                    foreach ($sector['rows'] ?? [] as $row) {
                        foreach ($row['seats'] ?? [] as $seatData) {
                            $items[] = [
                                'public_id' => (string) Str::ulid()->toBase32(),
                                'session_id' => $session->id,
                                'type' => 'seat',
                                'seat_id' => $seatData['id'],
                                'price_amount' => $seatData['price_amount'] ?? $row['price_amount'] ?? $session->default_price ?? 0,
                                'currency' => 'RUB',
                                'capacity' => 1,
                                'available_quantity' => 1,
                                'status' => 'available',
                                'metadata_json' => json_encode([
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
                        'public_id' => (string) Str::ulid()->toBase32(),
                        'session_id' => $session->id,
                        'type' => 'standing',
                        'standing_zone_id' => $zone['id'],
                        'price_amount' => $zone['price_amount'] ?? $session->default_price ?? 0,
                        'currency' => 'RUB',
                        'capacity' => $zone['capacity'],
                        'available_quantity' => $zone['capacity'],
                        'status' => 'available',
                        'metadata_json' => json_encode([
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
