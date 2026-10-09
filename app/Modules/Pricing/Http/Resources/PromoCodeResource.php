<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Modules\Orders\Models\PromoCode;

/**
 * Промокод для API — поля схемы `PromoCode` контракта (openapi.yaml).
 *
 * `value_percent` отдаётся числом, как объявлено в контракте (number), но
 * источник — строка DECIMAL(5,2): модель кастит её в string намеренно, чтобы
 * float не касался денежной арифметики движка цен. Здесь приведение обратно к
 * float безопасно: это витрина ответа, а не вход в расчёт.
 */
class PromoCodeResource extends JsonResource
{
    /**
     * @param PromoCode $resource
     */
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->public_id,
            'code' => (string) $this->code,
            'discount_type' => (string) $this->discount_type,
            'value_amount' => (int) $this->value_amount,
            'value_percent' => (float) $this->value_percent,
            'currency' => (string) $this->currency,
            'scope' => (string) $this->scope,
            'event_id' => $this->event_id === null ? null : (string) $this->event_id,
            'event_category_id' => $this->event_category_id === null ? null : (string) $this->event_category_id,
            'min_order_amount' => (int) $this->min_order_amount,
            'max_redemptions' => $this->max_redemptions === null ? null : (int) $this->max_redemptions,
            'per_user_limit' => (int) $this->per_user_limit,
            'redemptions_count' => (int) $this->redemptions_count,
            'status' => (string) $this->status,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
