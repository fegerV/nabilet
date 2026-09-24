<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SchemaVersionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'hall_id' => $this->hall_id,
            'version' => $this->version,
            'status' => $this->status,
            'width' => $this->width,
            'height' => $this->height,
            'background_url' => $this->background_url,
            'schema' => $this->schema_json,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}