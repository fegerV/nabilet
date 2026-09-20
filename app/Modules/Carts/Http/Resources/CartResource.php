<?php

declare(strict_types=1);

namespace App\Modules\Carts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'items_count' => $this->items_count,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency ?? 'RUB',
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'holds' => SeatHoldResource::collection($this->whenLoaded('holds')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
