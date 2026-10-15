<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка «Избранного» — схема `EventFavorite` контракта.
 *
 * `id` — `event_favorites.public_id` строкой: удаление отметки идёт по
 * мероприятию (`DELETE /me/events/{event}/favorite`), а не по этой строке,
 * поэтому идентификатор здесь справочный — но соглашение проекта (Wave 4)
 * требует public_id, а не внутренний BIGINT.
 */
class EventFavoriteResource extends JsonResource
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
