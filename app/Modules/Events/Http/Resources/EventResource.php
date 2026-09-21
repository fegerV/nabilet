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
            'title' => $this->getTranslation('title'),
            'description' => $this->getTranslation('description'),
            'short_description' => $this->getTranslation('short_description'),
            'start_date' => $this->start_date?->toIso8601String(),
            'end_date' => $this->end_date?->toIso8601String(),
            'timezone' => $this->timezone,
            'status' => $this->status,
            'is_featured' => $this->is_featured,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'currency' => $this->currency,
            'image_url' => $this->image_url,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
