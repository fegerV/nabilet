<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Http\Resources;

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
            // `items_count` в таблице `carts` нет (колонки: id, public_id,
            // user_id, cart_token, session_id, status, currency, total_amount,
            // expires_at, ...), поэтому ключ уходил `null` при любой корзине.
            // Считаем по загруженным позициям; если их не грузили — null, а не
            // выдуманный ноль.
            'items_count' => $this->relationLoaded('items') ? $this->items->count() : null,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency ?? 'RUB',
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'holds' => SeatHoldResource::collection($this->whenLoaded('holds')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
