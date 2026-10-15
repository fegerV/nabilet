<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Loyalty\Models\EventFavorite;

/**
 * Избранное: «❤️ — хочу посмотреть».
 *
 * СЕРВИС ТОНКИЙ НАМЕРЕННО
 *   Вся логика выбора записи живёт в `EventFavorite` (уникальный ключ +
 * `remember()`), а здесь — только то, чего не может сделать модель: понять,
 *   ЧЬЁ это избранное, и проверить, что мероприятие существует. Правил фазы 1
 * («не больше N избранных», «только опубликованные») нет намеренно: придумывать
 * их здесь значило бы выдумать соглашение, как в сплитах словарей status.
 *
 * ГОСТЬ В ИЗБРАННОЕ НЕ ПОПАДАЕТ
 *   Все пути модуля закрыты `auth:api` (`routes/api.php`), поэтому гость
 *   получает 401, а не тихо теряет отметку. Без аккаунта «избранное» негде
 *   хранить, и молчаливое «сохранено» было бы ложью.
 */
class FavoriteService
{
    /**
     * Добавить в избранное. Повторный вызов возвращает ту же отметку.
     */
    public function add(User $user, Event $event): EventFavorite
    {
        return EventFavorite::remember($user->getKey(), $event->getKey());
    }

    /**
     * Убрать из избранного. Отсутствие отметки — не ошибка: `DELETE`
     * идемпотентен по определению, и 204 на повторное нажатие отвечает правду.
     */
    public function remove(User $user, Event $event): bool
    {
        return EventFavorite::query()
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->delete() > 0;
    }

    public function isFavorite(User $user, Event $event): bool
    {
        return EventFavorite::query()
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->exists();
    }

    /**
     * Избранное одного пользователя, свежие сверху.
     *
     * Мероприятие и его предстоящие сеансы грузятся одним запросом: ресурс
     * показывает название и дату ближайшего сеанса, и без `with('event')`
     * список из 20 карточек сделал бы 21 запрос — то есть ровно ту N+1,
     * которую заметят на первом же покупателе с живым избранным.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EventFavorite>
     */
    public function forUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventFavorite::query()
            ->where('user_id', $user->getKey())
            ->with(['event' => static fn ($query) => $query->with(['sessions' => static fn ($sessions) => $sessions
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at'),
            ])])
            ->orderByDesc('created_at')
            ->get();
    }
}
