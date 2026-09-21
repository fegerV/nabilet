<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Services;

use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Repositories\EventRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Event service layer (ТЗ §12).
 *
 * Coordinates business logic for events, using domain objects and
 * repositories. Avoids magic strings by using EventStatus constants.
 */
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
        // Ensure status is valid if provided
        if (isset($data['status']) && !EventStatus::isValid($data['status'])) {
            throw new \InvalidArgumentException(
                sprintf('Invalid event status: %s', $data['status'])
            );
        }

        // Default to draft if no status provided
        $data['status'] = $data['status'] ?? EventStatus::DRAFT;

        return $this->eventRepository->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Event $event, array $data): Event
    {
        // Validate status if being updated
        if (isset($data['status']) && !EventStatus::isValid($data['status'])) {
            throw new \InvalidArgumentException(
                sprintf('Invalid event status: %s', $data['status'])
            );
        }

        return $this->eventRepository->update($event, $data);
    }

    public function delete(Event $event): bool
    {
        return $this->eventRepository->delete($event);
    }

    /**
     * Publish an event (transition to PUBLISHED status).
     */
    public function publish(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => EventStatus::PUBLISHED]);
    }

    /**
     * Archive an event (transition to ARCHIVED status).
     */
    public function archive(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => EventStatus::ARCHIVED]);
    }

    /**
     * Cancel an event (transition to CANCELLED status).
     */
    public function cancel(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => EventStatus::CANCELLED]);
    }

    /**
     * Schedule an event for future publication.
     */
    public function schedule(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => EventStatus::SCHEDULED]);
    }

    /**
     * Mark event as completed.
     */
    public function complete(Event $event): Event
    {
        return $this->eventRepository->update($event, ['status' => EventStatus::COMPLETED]);
    }

    /**
     * Check if event can be published.
     */
    public function canPublish(Event $event): bool
    {
        return in_array($event->status, [EventStatus::DRAFT, EventStatus::SCHEDULED], true);
    }

    /**
     * Check if event is publicly visible.
     */
    public function isPubliclyVisible(Event $event): bool
    {
        return EventStatus::isPubliclyVisible($event->status);
    }
}
