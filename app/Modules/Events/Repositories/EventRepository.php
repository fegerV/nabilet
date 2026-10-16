<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Repositories;

use Nabilet\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    public function __construct(
        private readonly Event $model
    ) {}

    /**
     * Каталог: список событий с СЕАНСАМИ, но НЕ с инвентарём
     * (аудит производительности 1.2).
     *
     * Раньше `sessions.inventoryItems` тащил ВСЕ строки инвентаря всех сеансов
     * всех событий страницы — десятки тысяч строк при зале в паре тысяч мест.
     * Ресурсу из инвентаря нужен только один факт: сколько позиций
     * `available` с `available_quantity > 0` у сеанса. Он считается ОДНИМ
     * сгруппированным запросом и вешается на сеанс атрибутом
     * `available_seats_count` (`EventResource` читает его первым и лишь при
     * отсутствии падает на инвентарь — админские пути, грузящие связь сами,
     * живут как жили).
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $paginator = $this->applyFilters($filters)
            ->with(['category', 'sessions.venue', 'sessions.hall'])
            ->paginate($perPage);

        $this->hydrateAvailableSeats($paginator->getCollection());

        return $paginator;
    }

    /**
     * Один COUNT по группе сеансов страницы вместо загрузки инвентаря.
     *
     * @param Collection<int, Event> $events
     */
    private function hydrateAvailableSeats(Collection $events): void
    {
        $eventIds = $events->modelKeys();

        if ($eventIds === []) {
            return;
        }

        $counts = DB::table('inventory_items')
            ->join('sessions', 'sessions.id', '=', 'inventory_items.session_id')
            ->whereIn('sessions.event_id', $eventIds)
            ->where('inventory_items.status', 'available')
            ->where('inventory_items.available_quantity', '>', 0)
            ->groupBy('inventory_items.session_id')
            ->select('inventory_items.session_id', DB::raw('COUNT(*) as available_seats_count'))
            ->pluck('available_seats_count', 'inventory_items.session_id');

        foreach ($events as $event) {
            foreach ($event->sessions as $session) {
                $session->available_seats_count = (int) ($counts[$session->id] ?? 0);
            }
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, Event>
     */
    public function all(array $filters = []): Collection
    {
        return $this->applyFilters($filters)->get();
    }

    public function find(int $id): ?Event
    {
        return $this->model->newQuery()->find($id);
    }

    public function findByPublicId(string $publicId): ?Event
    {
        return $this->model->newQuery()->where('public_id', $publicId)->first();
    }

    /**
     * Найти событие по slug — ГЛОБАЛЬНО, без привязки к организации.
     *
     * ПОЧЕМУ БЕЗ ОРГАНИЗАЦИИ (SEO-аудит P-03). Публичный адрес события —
     * `/event/{slug}/{publicId}` — организации не содержит. Раньше запрос
     * дополнительно фильтровался по организации, которую резолвил
     * `resolveOrganizationId()` с фолбэком «первая по `id`». Пока организация
     * одна, это было незаметно; с появлением второй страница события просто
     * перестала бы открываться: slug уникален глобально
     * (`uq_events_slug`, миграция `2026_10_11_000100`), событие принадлежит
     * второй организации, а фильтр искал в первой — и отдавал 404 на живой
     * адрес. Ровно этот 404 и воспроизводится в тесте
     * `EventBreadcrumbSeoTest`, если создать два события в разных организациях.
     *
     * Фильтр был бы нужен, если бы slug оставался уникальным только внутри
     * организации: тогда `first()` без фильтра вернул бы произвольную строку из
     * двух. Но именно поэтому slug и сделан глобально уникальным — при таком
     * индексе совпадение ровно одно, и организация в условии не несёт смысла.
     *
     * УБРАТЬ ЭТО УСЛОВИЕ БЫЛО БЕЗОПАСНО, потому что метод публичный по
     * назначению: его вызывают SEO-страница (`EventPageController`) и витрина
     * (`EventController::showBySlug`), где никакой границы арендатора нет и быть
     * не может — гость её не имеет.
     *
     * ЕСЛИ ПОЯВИТСЯ ВТОРАЯ ОРГАНИЗАЦИЯ: публичный маршрут должен получить
     * префикс организации (`/{orgSlug}/event/...`) или отдельный домен — тогда
     * фильтр вернётся, но уже по явному сегменту URL, а не по «первой
     * организации в базе». Место правки — ровно здесь.
     */
    public function findBySlug(string $slug): ?Event
    {
        return $this->model->newQuery()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Найти УДАЛЁННОЕ событие по slug (аудит P-24).
     *
     * Отдельный метод, а не флаг у `findBySlug()`, потому что смысл разный:
     * `findBySlug()` отвечает на вопрос «есть ли такая страница», а этот — «был
     * ли по этому адресу ресурс». Второй нужен ровно для одного: отдать 410
     * Gone вместо 404. Поисковик снимает URL с 410 быстрее и не считает его
     * ошибкой сайта, тогда как 404 на адресе, который ещё вчера отдавал 200,
     * означает «проверьте ссылку» и требует повторных обходов.
     *
     * `onlyTrashed()`, а не `withTrashed()`: если строка живая, обычный
     * `findBySlug()` уже её нашёл, и второй вызов здесь означал бы, что
     * вызывающий код спрашивает не то.
     */
    public function findTrashedBySlug(string $slug): ?Event
    {
        return $this->model->newQuery()
            ->onlyTrashed()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Event
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Event $event, array $data): Event
    {
        $event->update($data);
        return $event->fresh();
    }

    /**
     * Удалить событие — МЯГКО (аудит P-24).
     *
     * `$event->delete()` теперь проставляет `deleted_at` (трейт `SoftDeletes`
     * на модели), а не выполняет `DELETE`. Что именно это меняет — в докблоке
     * модели `Event`: физическое удаление каскадом уносило сеансы и переводы,
     * падало 500 при наличии проданных билетов (`fk_tickets_event` объявлен
     * `ON DELETE RESTRICT`) и освобождало `slug` под другое событие.
     */
    public function delete(Event $event): bool
    {
        return $event->delete();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(array $filters): Builder
    {
        $query = $this->model->newQuery();

        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Список статусов — то, что нужно публичному каталогу: он отдаёт
        // `publiclyVisible()` (published + completed) ОДНИМ запросом. Отдельный
        // ключ, а не массив в `status`: `where('status', ['a','b'])` в Eloquent
        // молча превращается в `= 'a'` (массив приводится к строке) — то есть
        // фильтр «любой из» тихо становился фильтром «равно первому».
        if (isset($filters['statuses']) && is_array($filters['statuses']) && $filters['statuses'] !== []) {
            $query->whereIn('status', $filters['statuses']);
        }

        if (isset($filters['is_featured']) && is_bool($filters['is_featured'])) {
            $query->where('is_featured', $filters['is_featured']);
        }

        return $query;
    }
}
