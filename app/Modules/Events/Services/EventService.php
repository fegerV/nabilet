<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Repositories\EventRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EventService
{
    public function __construct(
        private readonly EventRepository $eventRepository
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->eventRepository->paginate($filters, $perPage);
    }

    /**
     * @return Collection<int, Event>
     */
    public function all(array $filters = []): Collection
    {
        return $this->eventRepository->all($filters);
    }

    public function find(int $id): ?Event
    {
        return $this->eventRepository->find($id);
    }

    public function findByPublicId(string $publicId): ?Event
    {
        return $this->eventRepository->findByPublicId($publicId);
    }

    public function findBySlug(string $slug): ?Event
    {
        return $this->eventRepository->findBySlug($slug);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Event
    {
        return $this->eventRepository->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Event $event, array $data): Event
    {
        return $this->eventRepository->update($event, $data);
    }

    public function delete(Event $event): bool
    {
        return $this->eventRepository->delete($event);
    }

    public function publish(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => 'published']);
    }

    public function archive(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => 'archived']);
    }

    public function cancel(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => 'cancelled']);
    }
}
