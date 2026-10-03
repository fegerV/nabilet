<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeatHoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'inventory_item_id' => $this->inventoryItem->public_id,
            'seat_info' => $this->inventoryItem->whenLoaded('seat', fn() => [
                'number' => $this->inventoryItem->seat->number,
                'row' => $this->inventoryItem->seat->row->number,
                'sector' => $this->inventoryItem->seat->row->sector->name,
            ]),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
