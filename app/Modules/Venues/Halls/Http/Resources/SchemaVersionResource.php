<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Modules\Venues\Halls\Domain\SchemaPayloadNormalizer;

class SchemaVersionResource extends JsonResource
{
    public function toArray($request): array
    {
        // Eloquent-каст 'array' делает json_decode(..., true), поэтому пустой
        // объект `rowPrices: {}` снова становится PHP-массивом и уехал бы
        // клиенту как `[]`. Приводим форму на выходе: контракт §54 одинаков
        // независимо от того, заданы ли цены по рядам.
        $schema = $this->schema_json;
        if (is_array($schema)) {
            $schema = SchemaPayloadNormalizer::normalize($schema);
        }

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'hall_id' => $this->hall_id,
            'version' => $this->version,
            'revision' => $this->revision ?? 1,
            'status' => $this->status,
            'width' => $this->width,
            'height' => $this->height,
            'background_url' => $this->background_url,
            'schema' => $schema,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}