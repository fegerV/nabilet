<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Resources;

use Nabilet\Modules\Events\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var Event $this */
        $venue = $this->sessions
            ?->first(fn ($s) => $s->venue !== null)?->venue;

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'slug' => $this->slug,
            'organization_id' => $this->organization_id,
            // Макет билета. Отдаётся и id (для формы), и имя (для списка):
            // без имени админка показывала бы «Шаблон #4» и заставляла
            // открывать справочник, чтобы понять, что выбрано.
            'ticket_template_id' => $this->ticket_template_id,
            'ticket_template_name' => $this->whenLoaded('ticketTemplate', fn () => $this->ticketTemplate?->name),
            'category' => $this->whenLoaded('category', fn() => new EventCategoryResource($this->category)),
            'title' => $this->title,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'age_limit' => $this->age_limit,
            'duration_minutes' => $this->duration_minutes,
            'poster' => $this->poster,
            'cover' => $this->cover,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            // Директивы индексации (аудит P-23).
            //
            // Поле стало ЗАПИСЫВАЕМЫМ (`StoreEventRequest`/`UpdateEventRequest`
            // принимают его с проверкой директив), поэтому оно обязано быть и
            // ЧИТАЕМЫМ: иначе админка не сможет показать текущее значение и
            // правка стирала бы его на каждом сохранении — «поле есть, но
            // увидеть его негде» ровно так же ломает управление индексацией,
            // как отсутствие валидации.
            'robots' => $this->robots,
            'venue' => $venue ? [
                'name' => $venue->name,
                'city' => $venue->city ?? null,
            ] : null,
            // «от N ₽» на карточке витрины. Значение приходит атрибутом
            // `min_price_amount` от `EventRepository` (список афиши) или
            // `EventRepository::minPriceFor()` (карточка по slug). На путях без
            // расчёта (админка) — `null`: витрина отличает «цена есть» от
            // «цены нет» и показывает прочерк, а не 0 ₽.
            'price_from_minor' => $this->min_price_amount ?? null,
            'priceFromMinor' => $this->min_price_amount ?? null,
            'sessions' => $this->whenLoaded('sessions', fn() => $this->sessions
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'startsAt' => $s->starts_at?->toIso8601String(),
                    'hall' => $s->hall?->name,
                    // Аудит 1.2: каталог приносит `available_seats_count`
                    // (один COUNT на страницу), а не весь инвентарь сеанса.
                    // Фолбэк на коллекцию — для путей, где связь загружена,
                    // но агрегат не считался (админские сценарии).
                    'available_seats' => $s->available_seats_count
                        ?? $s->inventoryItems
                            ->filter(fn ($i) => $i->status === 'available' && ($i->available_quantity ?? 0) > 0)
                            ->count(),
                    'availableSeats' => $s->available_seats_count
                        ?? $s->inventoryItems
                            ->filter(fn ($i) => $i->status === 'available' && ($i->available_quantity ?? 0) > 0)
                            ->count(),
                ])
                ->toArray()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}