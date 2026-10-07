<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class HallResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'venue_id' => $this->venue_id,
            'description' => $this->description,
            'city' => $this->city,
            'address' => $this->address,
            'exterior_photo_url' => $this->exterior_photo_url,
            'interior_photo_url' => $this->interior_photo_url,
            'capacity' => $this->capacity,
            'width' => $this->width,
            'height' => $this->height,
            'status' => $this->status,
            'venue' => $this->whenLoaded('venue'),
        ];
    }
}
