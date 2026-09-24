<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Services;

use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Repositories\InventoryItemRepository;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\HallRow;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Modules\Venues\Models\Seat;
use Nabilet\Modules\Venues\Models\Sector;
use Nabilet\Modules\Venues\Models\StandingZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(
        protected InventoryItemRepository $repository
    ) {}

    /**
     * Генерирует из схемы зала:
     *   sectors → hall_rows → seats (геометрия) и inventory_items (места для продажи).
     *
     * Поддерживает оба формата schema_json:
     *   - канвас редактора (sectors[].seats[] с x/y/row/number) — конвертируется
     *     через HallSchemaVersion::toInventoryFormat();
     *   - rows-формат импортёра Афиши (sectors[].rows[].seats[] c price_amount)
     *     — используется напрямую.
     *
     * Геометрия пересоздаётся (deletе старых секторов/рядов/мест), затем вставляется
     * инвентарь со ссылками на свежесозданные места.
     */
    public function generateFromSchema(HallSchemaVersion $schemaVersion, Session $session): int
    {
        return DB::transaction(function () use ($schemaVersion, $session) {
            $schema = $schemaVersion->schema_json ?? [];

            // rows-формат (конвертация канваса, если нужно)
            $sectors = $schemaVersion->toInventoryFormat();

            // 1. Пересоздаём геометрию (sectors → rows → seats)
            $oldSectors = Sector::query()->where('schema_version_id', $schemaVersion->id)->get();
            foreach ($oldSectors as $sector) {
                $rows = HallRow::query()->where('sector_id', $sector->id)->get();
                foreach ($rows as $row) {
                    Seat::query()->where('row_id', $row->id)->delete();
                }
                HallRow::query()->where('sector_id', $sector->id)->delete();
            }
            Sector::query()->where('schema_version_id', $schemaVersion->id)->delete();

            $seatCounter = 0;
            $sectorModels = [];
            foreach ($sectors as $sectorData) {
                $sector = Sector::create([
                    'schema_version_id' => $schemaVersion->id,
                    'name' => $sectorData['name'] ?? 'Сектор',
                    'code' => $sectorData['code'] ?? '',
                    'type' => $sectorData['type'] ?? 'seated',
                    'x' => $sectorData['x'] ?? 0,
                    'y' => $sectorData['y'] ?? 0,
                    'width' => $sectorData['width'] ?? 60,
                    'height' => $sectorData['height'] ?? 40,
                    'capacity' => 0,
                ]);
                $sectorModels[] = $sector;

                                $capacity = 0;
                                                $isStanding = ($sectorData['type'] ?? 'seated') === 'standing';
                                                $standingPrice = 0;
                                foreach ($sectorData['rows'] ?? [] as $rowData) {
                                    $row = HallRow::create([
                                                            'sector_id' => $sector->id,
                                                            'number' => $rowData['number'] ?? '',
                                                            'name' => $rowData['label'] ?? 'Ряд',
                                                            'price_amount' => $rowData['price_amount'] ?? 0,
                                                            'currency' => 'RUB',
                                                            'rotation' => 0,
                                                        ]);

                                    foreach ($rowData['seats'] ?? [] as $seatData) {
                                                                            $seatType = (string) ($seatData['type'] ?? 'standard');
                                                                                                    // Нормализация типов: импорт из Афиши даёт 'regular',
                                                                                                    // БД допускает standard/vip/wheelchair/companion/custom.
                                                                                                    if ($seatType === 'regular') {
                                                                                                        $seatType = 'standard';
                                                                                                    }

                                                                                                    $seatCounter += 1;
                                                                                                    // Стоячая зона: физических мест нет — вместимость = N билетов.
                                                                                                    if ($isStanding) {
                                                                                                        $capacity += 1;
                                                                                                        if ($standingPrice === 0) {
                                                                                                            $standingPrice = (int) ($rowData['price_amount'] ?? 0);
                                                                                                        }
                                                                                                        continue;
                                                                                                    }
                                                                Seat::create([
                                                                    'row_id' => $row->id,
                                                                    'number' => (string) ($seatData['number'] ?? (string) $seatCounter),
                                                                    'label' => $seatData['label'] ?? ('Ряд ' . ($rowData['number'] ?? '') . ' Место ' . ($seatData['number'] ?? '')),
                                                                    'type' => $seatType,
                                                                                                'x' => $seatData['x'] ?? 0,
                                                                                                'y' => $seatData['y'] ?? 0,
                                                                                                'rotation' => 0,
                                                                                                'status' => 'active',
                                        ]);
                                        $capacity += 1;
                                    }
                                }
                $sector->update(['capacity' => $capacity]);
            }

            // 2. Пересоздаём инвентарь сессии из мест.
            // Standing-зоны и сидячие места вставляются ОТДЕЛЬНО: у них разный набор колонок,
            // а bulk insert (Model::insert) требует одинаковых ключей у всех строк.
                                    InventoryItem::where('session_id', $session->id)->delete();

                                    $seatItems = [];
                                    $standingItems = [];
                                                foreach ($sectorModels as $sector) {
                                                    // Стоячая зона: одна standing-зона + один inventory-item standing
                                                    // с capacity = вместимость зоны (сколько билетов продать).
                                                    if (($sector->type ?? 'seated') === 'standing' && $sector->capacity > 0) {
                                                        $standingRow = HallRow::query()->where('sector_id', $sector->id)->first();
                                                        $standingPrice = (int) ($standingRow?->price_amount ?? 0);
                                                        $zone = StandingZone::create([
                                                            'sector_id' => $sector->id,
                                                            'name' => $sector->name ?? 'Стоячая зона',
                                                            'capacity' => $sector->capacity,
                                                            'price_amount' => $standingPrice,
                                                            'currency' => 'RUB',
                                                        ]);
                                                        $standingItems[] = [
                                                            'public_id' => (string) Str::ulid()->toBase32(),
                                                            'session_id' => $session->id,
                                                            'type' => 'standing',
                                                            'seat_id' => null,
                                                            'standing_zone_id' => $zone->id,
                                                            'price_amount' => $standingPrice,
                                                            'currency' => 'RUB',
                                                            'capacity' => $sector->capacity,
                                                            'available_quantity' => $sector->capacity,
                                                            'status' => 'available',
                                                            'metadata_json' => json_encode([
                                                                'sector_name' => $sector->name,
                                                                'row_label' => 'Танцпол',
                                                                'seat_number' => null,
                                                            ]),
                                                            'created_at' => now(),
                                                            'updated_at' => now(),
                                                        ];
                                                        continue;
                                                    }
                                        $rows = HallRow::query()->where('sector_id', $sector->id)->get();
                                        foreach ($rows as $row) {
                                            $seats = Seat::query()->where('row_id', $row->id)->get();
                                            foreach ($seats as $seat) {
                                                $seatItems[] = [
                                                    'public_id' => (string) Str::ulid()->toBase32(),
                                                    'session_id' => $session->id,
                                                    'type' => 'seat',
                                                    'seat_id' => $seat->id,
                                                    'price_amount' => $row->price_amount,
                                                    'currency' => 'RUB',
                                                    'capacity' => 1,
                                                    'available_quantity' => 1,
                                                    'status' => 'available',
                                                    'metadata_json' => json_encode([
                                                        'sector_name' => $sector->name,
                                                        'row_label' => $row->name,
                                                        'seat_number' => $seat->number,
                                                    ]),
                                                    'created_at' => now(),
                                                    'updated_at' => now(),
                                                ];
                                            }
                                        }
                                    }

                                    $total = count($standingItems) + count($seatItems);
                        if ($total === 0) {
                            return 0;
                        }

                        if (count($standingItems) > 0) {
                            $this->repository->bulkCreate($standingItems);
                        }
                        if (count($seatItems) > 0) {
                            $this->repository->bulkCreate($seatItems);
                        }

                        return $total;
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