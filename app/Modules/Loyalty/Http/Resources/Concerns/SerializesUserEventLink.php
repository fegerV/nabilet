<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Http\Resources\Concerns;

use Illuminate\Http\Request;
use Nabilet\Modules\Loyalty\Http\Resources\EventSummaryResource;

/**
 * Форма строки «пользователь ↔ событие»: избранное и подписка.
 *
 * Оба списка отдавались с побайтово одинаковым `toArray()`, и «одинаковость»
 * держалась только на предложении в докблоке `EventFollowResource`: «Форма
 * намеренно совпадает с `EventFavoriteResource`». То есть правило жило в
 * прозе, а не в коде — четвёртый по счёту случай, когда объяснение
 * «почему эти две копии совпадают» было единственным, что их связывало.
 *
 * Почему это правило, а не совпадение. Экран кабинета показывает два списка
 * рядом, и разные формы заставляли бы фронтенд писать два парсера под одну и
 * ту же строку. Добавьте поле в один ресурс и забудьте про второй — контракт
 * расходится молча, и ломается это на клиенте, а не в тесте.
 *
 * `id` — `public_id` строкой, а не внутренний BIGINT: удаление отметки идёт по
 * мероприятию (`DELETE /me/events/{event}/favorite`), а не по этой строке,
 * поэтому идентификатор здесь справочный. Соглашение проекта (Wave 4) —
 * public_id.
 */
trait SerializesUserEventLink
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'event' => $this->whenLoaded('event', fn () => new EventSummaryResource($this->event)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
