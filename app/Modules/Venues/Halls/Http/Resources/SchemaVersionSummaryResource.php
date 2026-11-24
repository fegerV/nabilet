<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Лёгкая строка списка версий схемы.
 *
 * Полный `schema_json` может весить до 2 МБ на версию, а редактору для
 * выбора версии нужны только номер, статус, ревизия и фон. Геометрию он
 * догружает отдельным запросом выбранной версии — дёшево и без N+1 на список.
 */
class SchemaVersionSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'version' => $this->version,
            'revision' => $this->revision ?? 1,
            'status' => $this->status,
            'width' => $this->width,
            'height' => $this->height,
            'background_url' => $this->background_url,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
