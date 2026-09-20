<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Models\Session;
use App\Modules\Sessions\Http\Resources\SessionResource;
use App\Modules\Inventory\Items\Models\InventoryItem;
use Illuminate\Http\JsonResponse;

class SessionController extends Controller
{
    /**
     * Get session details with seatmap
     */
    public function show(string $publicId): JsonResponse
    {
        $session = Session::with([
            'event.organization',
            'hall.venue',
            'schemaVersion.sectors.rows.seats',
            'schemaVersion.sectors.standingZones'
        ])
        ->where('public_id', $publicId)
        ->firstOrFail();

        return response()->json([
            'data' => new SessionResource($session)
        ]);
    }

    /**
     * Get session seatmap with availability
     */
    public function seatmap(string $publicId): JsonResponse
    {
        $session = Session::with([
            'schemaVersion.sectors.rows.seats',
            'schemaVersion.sectors.standingZones'
        ])
        ->where('public_id', $publicId)
        ->firstOrFail();

        // Load inventory items for this session
        $inventory = InventoryItem::with(['seat.row.sector', 'standingZone'])
            ->where('session_id', $session->id)
            ->get()
            ->keyBy(fn($item) => $item->seat_id ?? $item->standing_zone_id . '_standing');

        return response()->json([
            'data' => [
                'session' => [
                    'id' => $session->public_id,
                    'starts_at' => $session->starts_at?->toIso8601String(),
                ],
                'seatmap' => [
                    'sectors' => $session->schemaVersion->sectors->map(fn($sector) => [
                        'id' => $sector->public_id,
                        'name' => $sector->name,
                        'type' => $sector->type,
                        'rows' => $sector->rows->map(fn($row) => [
                            'number' => $row->number,
                            'seats' => $row->seats->map(fn($seat) => [
                                'id' => $seat->public_id,
                                'number' => $seat->number,
                                'type' => $seat->type,
                                'status' => $inventory[$seat->id]?->status ?? 'available',
                                'price' => $inventory[$seat->id]?->price ?? 0,
                            ]),
                        ]),
                        'standing_zones' => $sector->standingZones->map(fn($zone) => [
                            'id' => $zone->public_id,
                            'name' => $zone->name,
                            'capacity' => $zone->capacity,
                            'available' => $inventory[$zone->id . '_standing']?->available_quantity ?? 0,
                            'price' => $inventory[$zone->id . '_standing']?->price ?? 0,
                        ]),
                    ]),
                ],
            ]
        ]);
    }
}
