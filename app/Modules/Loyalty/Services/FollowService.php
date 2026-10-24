<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Loyalty\Models\EventFollow;

/**
 * Подписки: «🔗 следить за мероприятием».
 *
 * Подписка отличается от избранного предназначением: она порождает письма
 * (см. `docs/LOYALTY-PLAN.md`, фаза 1), поэтому отписка здесь — это
 * прекращение рассылки, а не только исчезновение строки из списка.
 *
 * Отписка удаляет строку, а не помечает флагом «выключено»: у `event_follows`
 * нет колонки состояния, и появись она — это была бы вторая таблица сущностей
 * («активные» и «выключенные»), по которым никто не рассылает.
 */
class FollowService
{
    public function subscribe(User $user, Event $event): EventFollow
    {
        // Имя метода модели — `remember()`, как и у избранного: правило
        // «повторное действие возвращает ту же строку» одно на оба случая и
        // живёт в `RemembersUserEvent`. Здесь сохраняется доменное имя
        // «подписаться», потому что это язык сервиса, а не копия реализации.
        return EventFollow::remember($user->getKey(), $event->getKey());
    }

    /**
     * Отписаться. Повторный вызов — не ошибка: `DELETE` идемпотентен, и 204
     * отвечает правду («сейчас вы не подписаны»).
     */
    public function unsubscribe(User $user, Event $event): bool
    {
        return EventFollow::query()
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->delete() > 0;
    }

    public function isFollowing(User $user, Event $event): bool
    {
        return EventFollow::query()
            ->where('user_id', $user->getKey())
            ->where('event_id', $event->getKey())
            ->exists();
    }

    /**
     * Подписки одного пользователя, свежие сверху.
     *
     * Грузится так же, как `FavoriteService::forUser()`: список кабинета
     * показывает мероприятие и дату ближайшего сеанса, и без `with('event')`
     * это был бы запрос на каждую подписку.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EventFollow>
     */
    public function forUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return EventFollow::query()
            ->where('user_id', $user->getKey())
            ->with(['event' => static fn ($query) => $query->with(['sessions' => static fn ($sessions) => $sessions
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at'),
            ])])
            ->orderByDesc('created_at')
            ->get();
    }
}
