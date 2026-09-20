<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'doors_open_at' => $this->doors_open_at?->toIso8601String(),
            'is_published' => $this->is_published,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'currency' => $this->currency ?? 'RUB',
            'available_tickets' => $this->available_tickets,
            'event' => $this->whenLoaded('event', fn() => [
                'id' => $this->event->public_id,
                'title' => $this->event->title,
                'slug' => $this->event->slug,
            ]),
            'venue' => $this->whenLoaded('hall.venue', fn() => [
                'id' => $this->hall->venue->public_id,
                'name' => $this->hall->venue->name,
                'city' => $this->hall->venue->city,
                'address' => $this->hall->venue->address,
            ]),
            'hall' => $this->whenLoaded('hall', fn() => [
                'id' => $this->hall->public_id,
                'name' => $this->hall->name,
            ]),
            'schema_version' => $this->whenLoaded('schemaVersion', fn() => [
                'id' => $this->schemaVersion->public_id,
                'version' => $this->schemaVersion->version,
                'status' => $this->schemaVersion->status,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
