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
            'venue' => $venue ? [
                'name' => $venue->name,
                'city' => $venue->city ?? null,
            ] : null,
            'sessions' => $this->whenLoaded('sessions', fn() => $this->sessions
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'starts_at' => $s->starts_at?->toIso8601String(),
                    'startsAt' => $s->starts_at?->toIso8601String(),
                    'hall' => $s->hall?->name,
                    'available_seats' => $s->inventoryItems
                        ->filter(fn ($i) => $i->status === 'available' && ($i->available_quantity ?? 0) > 0)
                        ->count(),
                    'availableSeats' => $s->inventoryItems
                        ->filter(fn ($i) => $i->status === 'available' && ($i->available_quantity ?? 0) > 0)
                        ->count(),
                ])
                ->toArray()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}