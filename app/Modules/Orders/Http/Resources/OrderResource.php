<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'currency' => $this->currency ?? 'RUB',
            'payment_status' => $this->payment_status,
            'items_count' => $this->items_count,
            'customer' => [
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
                'first_name' => $this->customer_first_name,
                'last_name' => $this->customer_last_name,
            ],
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'tickets_count' => $this->whenCounted('tickets'),
            'organization' => $this->whenLoaded('organization', fn() => [
                'id' => $this->organization->public_id,
                'name' => $this->organization->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
