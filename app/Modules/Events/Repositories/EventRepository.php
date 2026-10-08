<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Repositories;

use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EventRepository
{
    public function __construct(
        private readonly Event $model
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->applyFilters($filters)
            ->with(['category', 'sessions.venue', 'sessions.hall', 'sessions.inventoryItems'])
            ->paginate($perPage);
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
     * Найти событие по slug.
     *
     * `slug` уникален НЕ глобально, а внутри организации
     * (`UNIQUE KEY uq_events_org_slug (organization_id, slug)`). Запрос без
     * фильтра по организации возвращал `first()` из произвольной строки — при
     * одной организации это незаметно, при двух означало бы «страница события
     * может открыть чужое событие с тем же адресом».
     *
     * КАК РАЗРЕШАЕТСЯ ОРГАНИЗАЦИЯ. Тот же приём, что и в
     * `StorefrontController::publicOrganizationId()` — единственный
     * существующий в проекте образец публичного тенант-резолва:
     *
     *   1. Организация из `OrganizationContext`, если она установлена
     *      (аутентифицированный запрос, админка, API с токеном).
     *   2. Иначе — первая организация по `id`. Это осознанный компромисс, а не
     *      «догадка»: инсталляция обслуживает одного организатора
     *      («Сургут-Концерт»), и витрина обязана открываться гостю, у которого
     *      никакого тенанта нет. Без фолбэка `OrganizationContext::id()` бросил
     *      бы `TenantContextMissingError`, и SEO-страница отдавала бы 500 —
     *      то есть fail-closed здесь сломал бы не безопасность, а сам продукт.
     *
     * ПОЧЕМУ НЕ `OrganizationContext::id()`. Он бросает исключение при
     * отсутствии контекста. Для служебных путей это правильно, для публичной
     * страницы — нет: гость по определению не имеет организации. `tryId()`
     * возвращает `null` вместо исключения, и дальше включается фолбэк.
     *
     * ЕСЛИ ПОЯВИТСЯ ВТОРАЯ ОРГАНИЗАЦИЯ: публичный маршрут должен получить
     * префикс организации (`/{orgSlug}/event/...`) или отдельный домен, а
     * фолбэк «первая по id» надо будет убрать — иначе он начнёт показывать
     * события не того организатора. Место правки — ровно здесь.
     */
    public function findBySlug(string $slug): ?Event
    {
        return $this->model->newQuery()
            ->where('slug', $slug)
            ->where('organization_id', $this->resolveOrganizationId())
            ->first();
    }

    /**
     * Организация, в рамках которой искать событие.
     *
     * Фолбэк на первую организацию — ровно та же семантика, что у публичной
     * витрины (`StorefrontController`).
     *
     * Возвращает `int`, а не строку: колонка `organization_id` объявлена
     * `BIGINT UNSIGNED`, и передавать сюда строку значило бы полагаться на
     * неявное приведение MySQL — из-за него в проекте уже ловили
     * `id = '1abc'` → событие 1 (`VenueController::store()`).
     *
     * `?? 0` вместо `null`: `where('organization_id', null)` в Eloquent
     * превращается в `IS NULL`, то есть вернёт ВСЕ строки с пустой
     * организацией вместо ни одной. `organization_id` объявлен `NOT NULL`,
     * поэтому `0` не совпадёт ни с чем — это и есть нужный fail-closed.
     */
    private function resolveOrganizationId(): int
    {
        $contextId = null;

        if (app()->bound(OrganizationContext::class)) {
            $context = app(OrganizationContext::class);

            if ($context instanceof OrganizationContext) {
                // `''` возвращается под `withoutScope()` — это «системный
                // контекст без организации», а не идентификатор. Приравнивать
                // его к организации нельзя.
                $candidate = $context->tryId();

                if (is_string($candidate) && $candidate !== '' && ctype_digit($candidate)) {
                    $contextId = (int) $candidate;
                }
            }
        }

        if ($contextId !== null) {
            return $contextId;
        }

        return (int) (Organization::query()->orderBy('id')->value('id') ?? 0);
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

        if (isset($filters['is_featured']) && is_bool($filters['is_featured'])) {
            $query->where('is_featured', $filters['is_featured']);
        }

        return $query;
    }
}
