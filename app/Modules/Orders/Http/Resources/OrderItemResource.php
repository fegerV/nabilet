<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'inventory_item' => [
                'id' => $this->inventoryItem->public_id,
                'type' => $this->inventoryItem->type,
                'price' => $this->inventoryItem->price,
                'seat' => $this->inventoryItem->whenLoaded('seat', fn() => [
                    'id' => $this->inventoryItem->seat->public_id,
                    'number' => $this->inventoryItem->seat->number,
                    'row' => $this->inventoryItem->seat->row?->number,
                    'sector' => $this->inventoryItem->seat->row?->sector?->name,
                ]),
            ],
        ];
    }
}
