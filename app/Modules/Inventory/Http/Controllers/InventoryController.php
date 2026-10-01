<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Http\Controllers;

use Nabilet\Modules\Inventory\Http\Requests\UpdateRowPricesRequest;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Services\InventoryService;
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
        $filters = $request->only(['session_id', 'status', 'type']);
        $perPage = (int) $request->get('per_page', 50);
        
        $query = InventoryItem::query()->with(['session', 'seat', 'standingZone']);
        
        if (isset($filters['session_id'])) {
            $query->where('session_id', $filters['session_id']);
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        
        $items = $query->paginate($perPage);
        
        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show(InventoryItem $inventoryItem): JsonResponse
    {
        $inventoryItem->load(['session', 'seat', 'standingZone']);
        
        return response()->json(['data' => $inventoryItem]);
    }

    public function availability(int $sessionId): JsonResponse
    {
        $available = InventoryItem::query()
            ->where('session_id', $sessionId)
            ->where('status', 'available')
            ->count();
            
        $held = InventoryItem::query()
            ->where('session_id', $sessionId)
            ->where('status', 'held')
            ->count();
            
        $sold = InventoryItem::query()
            ->where('session_id', $sessionId)
            ->where('status', 'sold')
            ->count();
        
        return response()->json([
            'data' => [
                'session_id' => $sessionId,
                'available' => $available,
                'held' => $held,
                'sold' => $sold,
                'total' => $available + $held + $sold,
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
     */
    public function updatePrices(UpdateRowPricesRequest $request, string $sessionId): JsonResponse
    {
        $result = $this->inventoryService->updateRowPrices(
            is_numeric($sessionId) ? (int) $sessionId : $sessionId,
            $request->validated()['rows']
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}
