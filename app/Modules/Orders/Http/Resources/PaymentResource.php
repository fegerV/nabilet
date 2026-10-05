<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as embedded in an order. Only the columns that exist on `payments`
 * are exposed — see `Nabilet\Modules\Payments\Models\Payment`, which records why
 * `organization_id` / `method` / `webhook_url` / `succeeded_at` / `failure_*`
 * are absent: the schema has no such columns.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_url' => $this->payment_url,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
