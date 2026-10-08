<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Файл для API.
 *
 * Поля совпадают со схемой `MediaAsset` контракта. Дополнительно отдаются три,
 * которых в схеме нет, и каждое — осознанно:
 *
 *  * `public_id` — публичный идентификатор (ULID). Числовой `id` остаётся в
 *    ответе для совместимости с уже написанным админским кодом, но в ссылках и
 *    между модулями используется `public_id`: он не говорит, сколько файлов в
 *    системе и в каком порядке они загружены.
 *  * `url` — готовый адрес. Клиент мог бы собрать его сам из `disk` и `path`,
 *    но тогда правило «`/storage/...` против корня» размножилось бы по фронтенду
 *    ровно так же, как когда-то размножилось по бэкенду (см. `AssetUrl`).
 *  * `links` — привязки к объектам, и только когда они подгружены. Галерея
 *    мероприятия без них не может показать, к какому объекту относится файл,
 *    а `position` задаёт порядок. Поле не добавляется, если связь не загружена:
 *    иначе каждый ответ тянул бы запрос, которого вызывающий не просил.
 */
class MediaAssetResource extends JsonResource
{
    public function toArray($request): array
    {
        $asset = $this->resource;

        $data = [
            'id' => (int) $asset->id,
            'public_id' => (string) $asset->public_id,
            'disk' => (string) $asset->disk,
            'path' => (string) $asset->path,
            'url' => $asset->url(),
            'filename' => (string) $asset->filename,
            'mime_type' => (string) $asset->mime_type,
            'size_bytes' => (int) $asset->size_bytes,
            'width' => $asset->width === null ? null : (int) $asset->width,
            'height' => $asset->height === null ? null : (int) $asset->height,
            'checksum' => $asset->checksum === null ? null : (string) $asset->checksum,
            'title' => $asset->title === null ? null : (string) $asset->title,
            'alt_text' => $asset->alt_text === null ? null : (string) $asset->alt_text,
            'variants_json' => $asset->variants_json,
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];

        if ($asset->relationLoaded('links')) {
            $data['links'] = $asset->links
                ->map(static fn ($link): array => [
                    'id' => (int) $link->id,
                    'entity_type' => (string) $link->entity_type,
                    'entity_id' => (int) $link->entity_id,
                    'role' => (string) $link->role,
                    'position' => (int) $link->position,
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
