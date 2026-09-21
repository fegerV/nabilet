<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Repositories;

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
        return $this->applyFilters($filters)->paginate($perPage);
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

    public function findBySlug(string $slug): ?Event
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
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
