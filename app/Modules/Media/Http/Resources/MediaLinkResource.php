<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Связь файла с объектом — элемент галереи.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ РЕСУРС, А НЕ `MediaAssetResource` СО СПИСКОМ СВЯЗЕЙ
 *
 * Галерея — это список СВЯЗЕЙ, а не список файлов. У одной связи есть роль и
 * позиция, у файла их нет: файл может лежать в трёх галереях сразу и в каждой
 * стоять на своём месте. Если отдавать файл с вложенным массивом связей,
 * потребитель получает «файл, у которого где-то есть позиция», и вынужден сам
 * искать нужную — то есть повторять работу, которую уже сделал SQL.
 *
 * Поэтому здесь связь снаружи, а файл — вложенным объектом `media`. Поля файла
 * разворачиваются плоско (`url`, `width`, `height`, `mime_type`), потому что
 * галерее нужен именно адрес и размеры, а не вся карточка: за остальным
 * потребитель идёт в `GET /media/{id}`.
 *
 * `media` может быть `null` — связь пережила файл. Такие связи отсеивает
 * `MediaService::forEntity()`, но ресурс обязан переживать `null` и сам:
 * он используется и там, где фильтрация не применялась.
 */
class MediaLinkResource extends JsonResource
{
    public function toArray($request): array
    {
        $link = $this->resource;
        $asset = $link->mediaAsset;

        return [
            'id' => (int) $link->id,
            'media_id' => (int) $link->media_asset_id,
            'entity_type' => (string) $link->entity_type,
            'entity_id' => (int) $link->entity_id,
            'role' => (string) $link->role,
            'position' => (int) $link->position,
            'created_at' => $link->created_at?->toIso8601String(),

            // Плоские поля файла: галерее нужен адрес и размеры, а не карточка.
            'media' => $asset === null ? null : [
                'id' => (int) $asset->id,
                'public_id' => (string) $asset->public_id,
                'url' => $asset->url(),
                'filename' => (string) $asset->filename,
                'mime_type' => (string) $asset->mime_type,
                'size_bytes' => (int) $asset->size_bytes,
                'width' => $asset->width === null ? null : (int) $asset->width,
                'height' => $asset->height === null ? null : (int) $asset->height,
                'title' => $asset->title === null ? null : (string) $asset->title,
                'alt_text' => $asset->alt_text === null ? null : (string) $asset->alt_text,
            ],
        ];
    }
}
