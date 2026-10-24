<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Nabilet\Modules\Loyalty\Http\Resources\Concerns\SerializesUserEventLink;

/**
 * Строка «Подписок» — схема `EventFollow` контракта.
 *
 * Форма строки общая с `EventFavoriteResource` и принадлежит
 * `SerializesUserEventLink`. Раньше это был второй побайтовый `toArray()`, и
 * совпадение держалось на предложении в этом докблоке — то есть правило жило
 * в прозе, а не в коде.
 *
 * Различие списков — в наличии кнопки «Отписаться», которая живёт в UI, а не
 * в данных.
 */
class EventFollowResource extends JsonResource
{
    use SerializesUserEventLink;
}
