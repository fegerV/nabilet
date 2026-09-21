<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Organizations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'slug' => $this->slug,
            'status' => $this->status,
            'logo_url' => $this->logo_url,
            'owner' => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->name,
                'email' => $this->owner?->email,
            ],
            'member_count' => $this->whenLoaded('members', fn () => $this->members->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
