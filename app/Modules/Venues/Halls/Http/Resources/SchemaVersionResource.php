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
            'state' => $this->state,
        ];
    }
}
