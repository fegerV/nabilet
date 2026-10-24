<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Modules\Loyalty\Http\Resources\Concerns\SerializesUserEventLink;

/**
 * Строка «Избранного» — схема `EventFavorite` контракта.
 *
 * Форма строки общая с `EventFollowResource` и принадлежит
 * `SerializesUserEventLink`: экран кабинета показывает два списка рядом, и
 * разные формы заставляли бы фронтенд писать два парсера под одну и ту же
 * строку.
 *
 * `id` — `event_favorites.public_id` строкой: удаление отметки идёт по
 * мероприятию (`DELETE /me/events/{event}/favorite`), а не по этой строке,
 * поэтому идентификатор здесь справочный — но соглашение проекта (Wave 4)
 * требует public_id, а не внутренний BIGINT.
 */
class EventFavoriteResource extends JsonResource
{
    use SerializesUserEventLink;
}
