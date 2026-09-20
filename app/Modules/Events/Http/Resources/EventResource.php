<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'city' => $this->city,
            'venue_name' => $this->venue?->name,
            'hall_name' => $this->halls->first()?->name,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'currency' => $this->currency ?? 'RUB',
            'available_tickets' => $this->available_tickets,
            'is_published' => $this->is_published,
            'category' => $this->whenLoaded('category', fn() => [
                'id' => $this->category->public_id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'organization' => $this->whenLoaded('organization', fn() => [
                'id' => $this->organization->public_id,
                'name' => $this->organization->name,
            ]),
            'sessions_count' => $this->whenCounted('sessions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
