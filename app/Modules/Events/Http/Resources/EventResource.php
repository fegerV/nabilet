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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}