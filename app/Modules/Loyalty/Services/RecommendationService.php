<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Database\Eloquent\Collection;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Loyalty\Domain\RecommendationPolicy;
use Nabilet\Modules\Loyalty\Models\EventFavorite;
use Nabilet\Modules\Loyalty\Models\EventFollow;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Персональные рекомендации мероприятий — «что показать покупателю дальше».
 *
 * РЕКОМЕНДАЦИИ БЕЗ НОВОЙ ТАБЛИЦЫ
 *   Профиль вкуса собирается из того, что покупатель УЖЕ сделал: отметил «❤️»
 *   (избранное), подписался на новые сеансы (подписка) и оплатил заказ. Все три
 *   сигнала ведут к мероприятию напрямую (`event_favorites.event_id`,
 *   `event_follows.event_id`, `orders.event_id`), поэтому ни одной новой
 *   таблицы и ни одной миграции не нужно — фаза 5 закрывается поверх уже
 *   существующей схемы. Просмотры (`events.view`) как сигнал намеренно НЕ
 *   берутся: их в схеме нет, а вводить счётчик просмотров ради полки
 *   «Рекомендуем» — это отдельная задача со своим дедупом и рейт-лимитом.
 *
 * СИГНАЛ — КАТЕГОРИЯ, А НЕ ЖАНР АРТИСТА
 *   В схеме «жанр» есть только у `event_artists`, у самого мероприятия — только
 *   `category_id`. Категория и есть та ось, по которой афиша уже сгруппирована
 *   (см. `EventCategory`), поэтому вкус считается по ней: категория события —
 *   устойчивый признак, а жанр артиста потребовал бы соединения через
 *   `event_artists` и дал бы профиль «из-за кулис», которого витрина не
 *   показывает.
 *
 * ТРИ СЛОЯ, КАК У БОНУСОВ И УРОВНЕЙ
 *   «Кого взять в кандидаты» и «посчитать вес категорий» требует базы — это
 *   здесь. «В каком порядке показать» — чистая функция
 *   `Domain\RecommendationPolicy`, исполняемая без `vendor/`. Модели остаются
 *   тонкими. Это тот же разделённый паттерн, что у `BonusService`/`BonusPolicy`
 *   и `LoyaltyLevelService`/`LoyaltyTierPolicy`.
 *
 * ПУСТОЙ ПРОФИЛЬ — ПУСТАЯ ПОЛКА, А НЕ СЛУЧАЙНЫЙ КАТАЛОГ
 *   У нового аккаунта нет ни одного сигнала, и «Рекомендуем» ему нечего
 *   показать. Отдать вместо этого первые попавшиеся события значило бы выдать
 *   общий каталог под видом персонального и соврать о том, что мы что-то знаем
 *   о вкусе. Поэтому сервис возвращает пустую коллекцию, а витрина просто не
 *   рисует полку.
 */
final class RecommendationService
{
    /**
     * Рекомендации для покупателя: предстоящие опубликованные мероприятия,
     * близкие по категориям к его прошлым взаимодействиям.
     *
     * @return Collection<int, Event> мероприятия с подгруженными предстоящими
     *                                 сеансами, в порядке показа
     */
    public function forUser(User $user, int $limit): Collection
    {
        return app(OrganizationContext::class)->withoutScope(
            fn (): Collection => $this->forUserScoped($user, $limit)
        );
    }

    private function forUserScoped(User $user, int $limit): Collection
    {
        $limit = RecommendationPolicy::clampLimit($limit);
        $userId = $user->getKey();

        $interacted = $this->interactedEventIds($userId);

        // Нет ни одного сигнала — нечего рекомендовать (см. докблок выше).
        if ($interacted === []) {
            return new Collection();
        }

        $weights = $this->tasteWeights($interacted);

        if ($weights === []) {
            return new Collection();
        }

        $candidates = $this->candidateEvents($interacted, array_keys($weights));

        if ($candidates->isEmpty()) {
            return new Collection();
        }

        // Кандидатов превращаем в простые дескрипторы: правило ранжирования
        // фреймворк-фри и не должно видеть модели `Event` и `Carbon`. Метку
        // времени переводим в секунды — единственный формат, сравнимый без
        // смещений часовых поясов.
        $descriptors = $candidates->map(static function (Event $event): array {
            $nextSession = $event->sessions->first();

            return [
                'event_id' => (int) $event->getKey(),
                'category_id' => $event->category_id === null ? null : (int) $event->category_id,
                'starts_at_ts' => $nextSession?->starts_at?->getTimestamp(),
            ];
        })->all();

        $orderedIds = RecommendationPolicy::rank($weights, $descriptors, $limit);

        // Возвращаем МОДЕЛИ, а не идентификаторы: контроллер отдаёт их через
        // `EventSummaryResource`, которому нужны подгруженные сеансы. Индекс по
        // id сохраняет порядок, вычисленный правилом, — `whereIn` вернул бы
        // строки в порядке базы, а не по релевантности.
        $byId = $candidates->keyBy(static fn (Event $event): int => (int) $event->getKey());

        $ordered = [];
        foreach ($orderedIds as $id) {
            $event = $byId->get($id);
            if ($event !== null) {
                $ordered[] = $event;
            }
        }

        return new Collection($ordered);
    }

    /**
     * Идентификаторы мероприятий, с которыми покупатель уже взаимодействовал:
     * избранное ∪ подписки ∪ оплаченные заказы.
     *
     * Объединение, а не три отдельных списка: покупатель мог и «полюбить»
     * мероприятие, и подписаться, и купить на него билет — во всех трёх случаях
     * это ОДНО событие, и в профиль вкуса оно должно войти один раз на каждый
     * факт, а из кандидатов — исчезнуть целиком.
     *
     * @return list<int>
     */
    private function interactedEventIds(int|string $userId): array
    {
        $favorites = EventFavorite::query()->where('user_id', $userId)->pluck('event_id');
        $follows = EventFollow::query()->where('user_id', $userId)->pluck('event_id');

        // Только ОПЛАЧЕННЫЕ заказы: неоплаченный заказ — это «ещё думает», а не
        // «купил». Он не делает мероприятие менее интересным, но и не должен
        // прятать его из рекомендаций раньше времени.
        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('status', OrderStateMachine::PAID)
            ->whereNotNull('event_id')
            ->pluck('event_id');

        return $favorites
            ->merge($follows)
            ->merge($orders)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Вес каждой категории в профиле вкуса: сколько раз мероприятия этой
     * категории встречаются среди взаимодействий покупателя.
     *
     * @param list<int> $interactedEventIds
     * @return array<int, int> category_id => вес
     */
    private function tasteWeights(array $interactedEventIds): array
    {
        return Event::query()
            ->whereIn('id', $interactedEventIds)
            ->whereNotNull('category_id')
            ->pluck('category_id')
            ->map(static fn ($id): int => (int) $id)
            ->countBy()
            ->all();
    }

    /**
     * Кандидаты на рекомендацию: опубликованные мероприятия из «вкусовых»
     * категорий, с будущими сеансами, исключая уже взаимодействованные.
     *
     * `whereHas` и `with` ограничены одним и тем же условием (`starts_at >= now`)
     * намеренно: `whereHas` решает, КОГО взять, а `with` — что положить в ответ.
     * Без ограничения в `with` ресурс взял бы `sessions->first()` без сортировки
     * и показал бы прошедший сеанс вместо ближайшего будущего.
     *
     * @param list<int> $interactedEventIds
     * @param list<int> $categoryIds
     * @return Collection<int, Event>
     */
    private function candidateEvents(array $interactedEventIds, array $categoryIds): Collection
    {
        return Event::query()
            ->whereIn('category_id', $categoryIds)
            ->where('status', EventStatus::PUBLISHED)
            ->whereNotIn('id', $interactedEventIds)
            ->whereHas('sessions', static fn ($query) => $query->where('starts_at', '>=', now()))
            ->with(['sessions' => static fn ($query) => $query
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at'),
            ])
            ->get();
    }
}
