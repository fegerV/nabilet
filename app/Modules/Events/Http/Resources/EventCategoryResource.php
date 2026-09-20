<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var EventCategory $this */
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->getTranslation('name'),
            'description' => $this->getTranslation('description'),
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
