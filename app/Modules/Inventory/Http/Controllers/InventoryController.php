<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Http\Controllers;

use Nabilet\Modules\Inventory\Http\Requests\UpdateRowPricesRequest;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Tickets\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}
    public function index(Request $request): JsonResponse
    {
        // `session_id` ОБЯЗАТЕЛЕН и валидируется. Раньше стояло
        // `isset($filters['session_id'])`, и при пустом `?session_id=` (или его
        // отсутствии) фильтр не применялся ВООБЩЕ: эндпоинт отдавал всю таблицу
        // `inventory_items` всех сеансов. Проверено на живом стенде —
        // `GET /api/v1/inventory?per_page=500` возвращал 55 строк из 4 сеансов
        // (схемы, цены и статусы чужих мероприятий). Fail-closed: без валидного
        // сеанса — 422, а не «всё подряд».
        $validated = $request->validate([
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
            'status' => ['nullable', 'string', 'max:32'],
            'type' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = InventoryItem::query()
            // `seat.row.sector` нужны витрине: покупатель обязан видеть настоящий
            // НОМЕР ряда (`hall_rows.number` = 1, 2, …) и имя сектора («Партер»),
            // а не внутренний `row_id` (13, 14, 15) и захардкоженный «Зал».
            ->with(['session', 'seat.row.sector', 'standingZone'])
            ->where('session_id', $validated['session_id'])
            ->orderBy('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $items = $query->paginate($perPage);

        // Покупательский контракт: к `seat` добавляем `row_number` и `sector_name`.
        // `row_id` остаётся — на нём построена группировка рядов в `SeatSelectionPage`.
        $items->setCollection(
            $items->getCollection()->map(function (InventoryItem $item): InventoryItem {
                $seat = $item->seat;
                if ($seat !== null) {
                    $seat->setAttribute('row_number', $seat->row?->number);
                    $seat->setAttribute('sector_name', $seat->row?->sector?->name);
                }

                return $item;
            })
        );

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
                // Лимит билетов на заказ отдаём витрине ИЗ ТОГО ЖЕ источника,
                // который проверяет `CartController::addItem`
                // (config/nabilet.php → CHECKOUT_MAX_ITEMS).
                //
                // Зачем в ответе: пока число было продублировано на клиенте
                // константой `MAX_TICKETS_PER_ORDER`, снижение лимита на сервере
                // давало «кнопка нажимается, а запрос отвергается 422-м» —
                // покупатель видел техническую ошибку вместо понятного
                // «не больше N билетов». Теперь клиент не угадывает.
                'max_tickets_per_order' => max(1, (int) config('nabilet.checkout.max_items_per_order', 10)),
                // С какой стороны полотна рисовать сцену — см. `stageSide()`.
                'stage_side' => $this->stageSide($validated['session_id'] ?? null),
            ],
        ]);
    }

    /**
     * С какой стороны полотна нарисована сцена: `top` или `bottom`.
     *
     * ЗАЧЕМ СЕРВЕРУ ЭТО ЗНАНИЕ. `CoordSeatMap` рисовал полосу «СЦЕНА» жёстко
     * сверху. Для зала, где места идут от сцены вверх, подпись оказывалась на
     * противоположной стороне: у «Театра СурГУ» ряд 1 стоит внизу (рядом со
     * сценой), ряд 16 — у задней стены, и витрина показывала сцену за спиной
     * зрителя. По самим местам направление не угадывается — оно лежит в схеме
     * зала, в статичном объекте `kind: "stage"`, и больше нигде.
     *
     * Сравниваем центр сцены с медианой Y мест: медиана, а не среднее — в
     * залах с боковыми блоками и балконом среднее уезжает от реальной
     * середины партера. Нет сцены в схеме (танцпол, клуб) — остаётся `top`,
     * как было.
     */
    private function stageSide(?string $sessionId): string
    {
        if ($sessionId === null || $sessionId === '') {
            return 'top';
        }

        // `session_id` валидирован как integer (см. выше), поэтому ищем по id;
        // `orWhere` по public_id оставлен на случай, если фильтр когда-нибудь
        // расширят — стоимость нулевая.
        $session = Session::query()
            ->where('id', $sessionId)
            ->orWhere('public_id', $sessionId)
            ->first();

        $schema = $session?->schemaVersion?->schema_json;

        if (! is_array($schema)) {
            return 'top';
        }

        $stage = null;

        foreach ((array) ($schema['staticObjects'] ?? []) as $object) {
            if (is_array($object) && ($object['kind'] ?? null) === 'stage') {
                $stage = $object;
                break;
            }
        }

        if ($stage === null) {
            return 'top';
        }

        $seatYs = [];

        foreach ((array) ($schema['sectors'] ?? []) as $sector) {
            if (! is_array($sector)) {
                continue;
            }

            $offsetY = (float) ($sector['y'] ?? 0);

            foreach ((array) ($sector['seats'] ?? []) as $seat) {
                if (is_array($seat)) {
                    $seatYs[] = $offsetY + (float) ($seat['y'] ?? 0);
                }
            }
        }

        if ($seatYs === []) {
            return 'top';
        }

        sort($seatYs);
        $medianY = $seatYs[intdiv(count($seatYs), 2)];

        $stageCenterY = (float) ($stage['y'] ?? 0) + (float) ($stage['height'] ?? 0) / 2;

        return $stageCenterY > $medianY ? 'bottom' : 'top';
    }

    public function show(InventoryItem $inventoryItem): JsonResponse
    {
        $inventoryItem->load(['session', 'seat.row.sector', 'standingZone']);

        if ($inventoryItem->seat !== null) {
            $inventoryItem->seat->setAttribute('row_number', $inventoryItem->seat->row?->number);
            $inventoryItem->seat->setAttribute('sector_name', $inventoryItem->seat->row?->sector?->name);
        }

        return response()->json(['data' => $inventoryItem]);
    }

    /**
     * GET /inventory/sessions/{sessionId}/availability — сколько билетов можно
     * ещё продать на сеанс.
     *
     * Что было не так. Эндпоинт считал СТРОКИ `inventory_items`, а не билеты.
     * У сидячего места вместимость 1, поэтому для чисто сидячего зала числа
     * совпадали и ошибка была незаметна. Но стоячая зона — это ОДНА строка с
     * `capacity = N` (см. InventoryService::generateFromSchema), поэтому зал
     * «280 мест + танцпол на 150» отдавал `total = 281` вместо 430: витрина и
     * админка видели в 2.5 раза меньше билетов, чем реально продаётся.
     *
     * Что стало. Ответ разделён на две честные части:
     *   • `tickets`   — билеты (единица продажи): вместимость, свободно,
     *                   зарезервировано под корзины, продано;
     *   • `positions` — позиции склада (единица учёта), в точности те числа,
     *                   что эндпоинт отдавал раньше.
     *
     * `positions` сохранён целиком (и именно он — прежний контракт), поэтому
     * клиент, которому нужны строки склада, ничего не теряет. Потребителей у
     * эндпоинта на момент правки не было (проверено поиском по `resources/js`,
     * `app`, `tests`), так что менять форму ответа безопасно.
     *
     * «Продано» берётся из `tickets`, а не из `status = 'sold'` у позиции:
     * оплата помечает позицию целиком (PaymentService::markInventorySoldForOrder),
     * поэтому у частично распроданной стоячей зоны статус позиции «sold» ничего
     * не говорит о числе проданных билетов.
     */
    public function availability(int $sessionId): JsonResponse
    {
        $positions = [
            'available' => InventoryItem::query()->where('session_id', $sessionId)->where('status', 'available')->count(),
            'held' => InventoryItem::query()->where('session_id', $sessionId)->where('status', 'held')->count(),
            'sold' => InventoryItem::query()->where('session_id', $sessionId)->where('status', 'sold')->count(),
        ];
        $positions['total'] = $positions['available'] + $positions['held'] + $positions['sold'];

        $capacity = (int) InventoryItem::query()->where('session_id', $sessionId)->sum('capacity');
        $availableTickets = (int) InventoryItem::query()->where('session_id', $sessionId)->sum('available_quantity');
        $soldTickets = (int) Ticket::query()
            ->where('session_id', $sessionId)
            ->whereIn('status', ['issued', 'used'])
            ->count();

        // Резерв — то, что уже разобрано корзинами/холдами, но ещё не оплачено.
        // Отрицательным быть не может: если билеты проданы, а позиции ещё не
        // помечены 'sold', разница уходит в 0, а не в минус.
        $reserved = max(0, $capacity - $availableTickets - $soldTickets);

        return response()->json([
            'data' => [
                'session_id' => $sessionId,
                'tickets' => [
                    'capacity' => $capacity,
                    'available' => $availableTickets,
                    'reserved' => $reserved,
                    'sold' => $soldTickets,
                    'sold_out' => $availableTickets === 0,
                ],
                'positions' => $positions,
            ],
        ]);
    }

    /**
     * PATCH /inventory/sessions/{sessionId}/prices — admin-only.
     *
     * Обновляет цены рядов без регенерации геометрии: inventory_items.price_amount
     * меняется только у свободных позиций (available_quantity = capacity), холды и
     * проданные билеты остаются нетронутыми; открытые корзины синхронизируются.
     * Полная семантика — InventoryService::updateRowPrices().
     *
     * `session_only=true` в теле — цены применяются только к инвентарю этого
     * сеанса, сетка зала (hall_rows / standing_zones) не перезаписывается.
     */
    public function updatePrices(UpdateRowPricesRequest $request, string $sessionId): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->inventoryService->updateRowPrices(
            is_numeric($sessionId) ? (int) $sessionId : $sessionId,
            $validated['rows'],
            (bool) ($validated['session_only'] ?? false)
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
