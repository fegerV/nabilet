<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка «Подписок» — схема `EventFollow` контракта.
 *
 * Форма намеренно совпадает с `EventFavoriteResource`: экран кабинета показывает
 * два списка рядом, и разные формы заставляли бы фронтенд писать два парсера
 * под одну и ту же строку. Различие списков — в наличии кнопки «Отписаться»,
 * которая живёт в UI, а не в данных.
 */
class EventFollowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'event' => $this->whenLoaded('event', fn () => new EventSummaryResource($this->event)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
