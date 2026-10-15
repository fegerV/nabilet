<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка мероприятия внутри «Избранного» и «Подписок» — не вся карточка
 * события, а ровно то, чего не хватает строке списка в кабинете.
 *
 * `id` — `events.public_id` строкой, как в `AuthResource` и `OrderResource`
 * (соглашение Wave 4: внутренний BIGINT не покидает API). Именно по нему
 * витрина строит ссылку `/event/{slug}/{publicId}`.
 *
 * `starts_at` — ближайший предстоящий сеанс или `null`, если все сеансы в
 * прошлом. Без даты «Избранное» — список названий без календаря, и покупатель
 * не может решить, на что он ещё хочет попасть. Загружается из активно
 * загруженной коллекции `sessions` (см. `FavoriteService::forUser`), поэтому
 * здесь только выбор первого подходящего элемента.
 */
class EventSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \Nabilet\Modules\Events\Models\Event $this */
        $nextSession = $this->relationLoaded('sessions')
            ? $this->sessions->first()
            : null;

        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'poster' => $this->poster,
            'age_limit' => $this->age_limit,
            'starts_at' => $nextSession?->starts_at?->toIso8601String(),
        ];
    }
}
