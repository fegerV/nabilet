<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Движение по бонусному счёту — схема `BonusTransaction` контракта.
 *
 * `delta` знаковый и в минорных единицах: плюс — начислили, минус — списали,
 * вернули или сожгли по сроку. Знак здесь не оформление, а смысл: `reason` без
 * знака не отличает начисление от отката, а знак без `reason` не отличает кэшбэк
 * от подарка за регистрацию. Клиенту нужны оба, поэтому отдаются оба.
 *
 * `order_id` — `orders.public_id`, а не внутренний BIGINT: соглашение проекта
 * (`DATABASE.md` §9) — внутренний идентификатор не покидает API. Заказ
 * подгружается контроллером (`with('order')`), поэтому ключ присутствует всегда,
 * а `null` означает «движение не связано с заказом» — подарки за регистрацию и
 * первую покупку, а также сгорание по сроку.
 */
class BonusTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_id' => $this->whenLoaded(
                'order',
                fn (): ?string => $this->order?->public_id,
            ),
            'delta' => (int) $this->delta,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
