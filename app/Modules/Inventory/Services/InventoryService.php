<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Services;

use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Repositories\InventoryItemRepository;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\HallRow;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Modules\Venues\Models\HallTable;
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

            // 0. Снимаем инвентарь сессии ДО удаления геометрии.
            //
            // Порядок критичен: `inventory_items.seat_id` и `standing_zone_id`
            // ссылаются на `seats` / `standing_zones` с ON DELETE RESTRICT, а
            // позиции инвентаря удалялись в конце метода. Повторный вызов
            // generateFromSchema() для той же сессии (пересборка зала после
            // правки схемы) падал с 1451 «Cannot delete or update a parent row»
            // на первом же месте — то есть пересобрать инвентарь было нельзя
            // вообще, ни разу. Проданные билеты по-прежнему защищены: у
            // `tickets.seat_id` тоже RESTRICT, поэтому попытка пересобрать зал с
            // продажами по-прежнему падает — но уже явно и не разрушая данные.
            InventoryItem::where('session_id', $session->id)->delete();

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
                                                                    // Индивидуальная цена места (§54): побеждает цену ряда.
                                                                    'price_amount' => isset($seatData['priceMinor']) && is_numeric($seatData['priceMinor']) && (int) $seatData['priceMinor'] > 0
                                                                        ? (int) $seatData['priceMinor']
                                                                        : null,
                                        ]);
                                        $capacity += 1;
                                    }
                                }
                $sector->update(['capacity' => $capacity]);

                // Банкетный стол (сектор `shape: 'table'`) — материализуем его
                // геометрию в `hall_tables`. Раньше таблица не заполнялась
                // НИКОГДА (0 строк во всех БД): стол существовал только как
                // кольцо мест внутри JSON-схемы, а из ТЗ-таблица оставалась
                // мёртвой. Старые строки не чистим вручную — `hall_tables`
                // ссылается на `sectors` с ON DELETE CASCADE, а сектора выше
                // пересоздаются.
                if (($sectorData['shape'] ?? null) === 'table' && is_array($sectorData['table'] ?? null)) {
                    $table = $sectorData['table'];
                    HallTable::create([
                        'sector_id' => $sector->id,
                        'name' => (string) ($sectorData['name'] ?? 'Стол'),
                        // width/height в БД NOT NULL, поэтому вырожденное кольцо
                        // (все места в одной точке) даёт минимальный размер,
                        // а не 0 — иначе строка не вставится.
                        'x' => (float) ($table['x'] ?? 0),
                        'y' => (float) ($table['y'] ?? 0),
                        'width' => max(1.0, (float) ($table['width'] ?? 0)),
                        'height' => max(1.0, (float) ($table['height'] ?? 0)),
                        'rotation' => 0,
                        'capacity' => (int) ($table['capacity'] ?? 0),
                        // Радиус кольца — не колонка, а метаданные: по нему
                        // витрина рисует круглый стол, а не прямоугольник.
                        'metadata_json' => [
                            'cx' => (float) ($table['cx'] ?? 0),
                            'cy' => (float) ($table['cy'] ?? 0),
                            'ring' => (float) ($table['ring'] ?? 0),
                        ],
                    ]);
                }
            }

            // 2. Собираем инвентарь сессии из мест.
            // Standing-зоны и сидячие места вставляются ОТДЕЛЬНО: у них разный набор колонок,
            // а bulk insert (Model::insert) требует одинаковых ключей у всех строк.
            // Прежний инвентарь уже снят шагом 0 (до удаления геометрии).
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
                                                    // Индивидуальная цена места перекрывает цену ряда.
                                                    'price_amount' => $seat->price_amount ?? $row->price_amount,
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

    /**
     * Admin-обновление цен по рядам БЕЗ регенерации геометрии.
     *
     * Проблема, которую это решает: цены живут в inventory_items.price_amount и
     * проставляются только в generateFromSchema(), а роуты Inventory — только GET.
     * Единственный способ менять цену был — пересоздать инвентарь, что убивает
     * холды (seat_holds) и проданные билеты (status='sold').
     *
     * Правила:
     *   - обновляем price_amount только у СВОБОДНЫХ мест: available_quantity = capacity
     *     (для seat capacity=1, для standing capacity=N — «не трогать занятые места»);
     *   - холды и sold-позиции остаются со старой ценой — контракт покупки не меняется
     *     задним числом;
     *   - hall_rows / standing_zones обновляем всегда: следующий generateFromSchema()
     *     возьмёт новую цену как источник истины ряда;
     *   - cart_items открытых (active) корзин синхронизируются на новую цену и
     *     пересчитываются total_price / carts.total_amount, чтобы покупатель платил
     *     ровно то, что видит витрина.
     *
     * Всё в одном DB::transaction — либо весь прайс-лист применён, либо ничего.
     *
     * @param  int   $sessionId  числовой id или public_id сессии (как в CartService)
     * @param  array<int, array{row_id?: int|string, standing_zone_id?: int|string, price_amount: int|string}>  $rows
     * @return array{session_id:int, rows_updated:int, items_updated:int, cart_items_updated:int, carts_recalculated:int}
     */
    public function updateRowPrices(int|string $sessionId, array $rows): array
    {
        $session = Session::findOrFailBySessionId($sessionId);

        return DB::transaction(function () use ($session, $rows): array {
            $now = now();
            $rowsUpdated = 0;
            $itemsUpdated = 0;
            $cartItemsUpdated = 0;
            $affectedItemIds = [];

            foreach ($rows as $rowData) {
                $price = (int) $rowData['price_amount'];

                if (! empty($rowData['standing_zone_id'])) {
                    $zoneId = (int) $rowData['standing_zone_id'];

                    // Зона должна принадлежать этой сессии (через её инвентарь) —
                    // иначе админ одного города правил бы цены чужого зала.
                    $owns = InventoryItem::query()
                        ->where('session_id', $session->id)
                        ->where('standing_zone_id', $zoneId)
                        ->exists();
                    if (! $owns) {
                        throw new NotFoundError('Standing zone', $zoneId, [
                            'reason' => 'зона не относится к сессии ' . $session->id,
                        ]);
                    }

                    StandingZone::query()
                        ->whereKey($zoneId)
                        ->update(['price_amount' => $price, 'updated_at' => $now]);
                    $rowsUpdated++;

                    $touched = InventoryItem::query()
                        ->where('session_id', $session->id)
                        ->where('standing_zone_id', $zoneId)
                        ->whereColumn('available_quantity', 'capacity')
                        ->pluck('id');
                    $affectedItemIds = array_merge($affectedItemIds, $touched->all());

                    $itemsUpdated += InventoryItem::query()
                        ->where('session_id', $session->id)
                        ->where('standing_zone_id', $zoneId)
                        ->whereColumn('available_quantity', 'capacity')
                        ->update(['price_amount' => $price, 'updated_at' => $now]);

                    continue;
                }

                $rowId = (int) $rowData['row_id'];

                $row = HallRow::find($rowId);
                if ($row === null) {
                    throw new NotFoundError('Hall row', $rowId);
                }
                // Ряд должен быть из схемы, по которой генерирован инвентарь сессии.
                $inSession = Seat::query()
                    ->where('row_id', $rowId)
                    ->whereIn('id', function ($q) use ($session): void {
                        $q->select('seat_id')
                            ->from('inventory_items')
                            ->where('session_id', $session->id)
                            ->whereNotNull('seat_id');
                    })
                    ->exists();
                if (! $inSession) {
                    throw new NotFoundError('Hall row', $rowId, [
                        'reason' => 'ряд не относится к сессии ' . $session->id,
                    ]);
                }

                HallRow::query()
                    ->whereKey($rowId)
                    ->update(['price_amount' => $price, 'currency' => 'RUB', 'updated_at' => $now]);
                $rowsUpdated++;

                $touched = InventoryItem::query()
                    ->where('session_id', $session->id)
                    ->whereIn('seat_id', function ($q) use ($rowId): void {
                        $q->select('id')->from('seats')->where('row_id', $rowId);
                    })
                    ->whereColumn('available_quantity', 'capacity')
                    ->pluck('id');
                $affectedItemIds = array_merge($affectedItemIds, $touched->all());

                $itemsUpdated += InventoryItem::query()
                    ->where('session_id', $session->id)
                    ->whereIn('seat_id', function ($q) use ($rowId): void {
                        $q->select('id')->from('seats')->where('row_id', $rowId);
                    })
                    ->whereColumn('available_quantity', 'capacity')
                    ->update(['price_amount' => $price, 'updated_at' => $now]);
            }

            // Синхронизация ОТКРЫТЫХ корзин (status='active'): unit_price и
            // total_price = новая цена × количество. Конвертированные/закрытые
            // корзины не трогаем — заказ уже зафиксировал цену.
            $cartItemsUpdated = $this->syncOpenCartItems($affectedItemIds, $now);
            $cartsRecalculated = $this->recalculateAffectedCarts();

            return [
                'session_id' => (int) $session->id,
                'rows_updated' => $rowsUpdated,
                'items_updated' => $itemsUpdated,
                'cart_items_updated' => $cartItemsUpdated,
                'carts_recalculated' => $cartsRecalculated,
            ];
        });
    }

    /**
     * Обновляет unit_price/total_price cart_items, ссылающихся на переоцененные
     * позиции, но только внутри активных корзин.
     *
     * @param  list<int>  $inventoryItemIds
     */
    protected function syncOpenCartItems(array $inventoryItemIds, $now): int
    {
        if ($inventoryItemIds === []) {
            return 0;
        }

        $updated = 0;
        $items = CartItem::query()
            ->whereIn('inventory_item_id', $inventoryItemIds)
            ->whereHas('cart', fn ($q) => $q->where('status', 'active'))
            ->with('inventoryItem:id,price_amount')
            ->get();

        foreach ($items as $item) {
            $newUnit = (int) ($item->inventoryItem?->price_amount ?? $item->unit_price);
            $newTotal = $newUnit * (int) $item->quantity;

            if ((int) $item->unit_price === $newUnit && (int) $item->total_price === $newTotal) {
                continue;
            }

            $item->forceFill([
                'unit_price' => $newUnit,
                'total_price' => $newTotal,
                'updated_at' => $now,
            ])->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * Пересчёт carts.total_amount по всем активным корзинам (та же формула, что
     * в CartItemService::recalculateCartTotal — сумма total_price позиций).
     */
    protected function recalculateAffectedCarts(): int
    {
        $carts = Cart::query()->where('status', 'active')->get();
        $recalculated = 0;

        foreach ($carts as $cart) {
            $total = (int) CartItem::query()
                ->where('cart_id', $cart->id)
                ->sum('total_price');

            if ((int) ($cart->total_amount ?? 0) !== $total) {
                $cart->forceFill(['total_amount' => (string) $total])->save();
                $recalculated++;
            }
        }

        return $recalculated;
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