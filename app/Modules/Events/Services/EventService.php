<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Services;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Repositories\EventRepository;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Illuminate\Support\Facades\DB;
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
        private readonly EventRepository $eventRepository,
        private readonly InventoryService $inventoryService,
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
     * Удалённое событие по slug — для ответа 410 Gone (аудит P-24).
     *
     * Отдельный метод, а не флаг у `findBySlug()`: вызывающий код должен
     * ВИДЕТЬ, что спрашивает про удалённое, иначе легко перепутать «страницы
     * нет» с «страница была и удалена» — а это разные коды ответа.
     */
    public function findTrashedBySlug(string $slug): ?Event
    {
        return $this->eventRepository->findTrashedBySlug($slug);
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

        $event = $this->eventRepository->create($data);
        $this->syncPrimarySession($event, $data);

        return $event->fresh();
    }

    /**
     * Мероприятие выглядит одной сущностью, но продажи идут через сеанс:
     * корзины, инвентарь, билеты и чек-ин ссылаются на session_id. Поэтому
     * дата/площадка мероприятия синхронизируются со скрытым основным сеансом.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncPrimarySession(Event $event, array $data): void
    {
        if ($event->starts_at === null || $event->venue_id === null) {
            return;
        }

        DB::transaction(function () use ($event, $data): void {
            $session = $event->primary_session_id !== null
                ? Session::query()->find($event->primary_session_id)
                : null;

            $hall = Hall::query()->where('venue_id', $event->venue_id)->first();

            $schemaVersionId = $event->schema_version_id;
            if ($schemaVersionId === null) {
                $schemaQuery = HallSchemaVersion::query()
                    ->where('status', 'published')
                    ->when(
                        $hall !== null,
                        static fn ($q) => $q->where(
                            static fn ($q) => $q->where('venue_id', $event->venue_id)
                                ->orWhere('hall_id', $hall->id),
                        ),
                        static fn ($q) => $q->where('venue_id', $event->venue_id),
                    );
                $schemaVersionId = $schemaQuery->orderByDesc('id')->value('id');
            }
            if ($hall === null && $schemaVersionId !== null) {
                $legacyHallId = HallSchemaVersion::query()->whereKey($schemaVersionId)->value('hall_id');
                $hall = $legacyHallId !== null ? Hall::query()->find($legacyHallId) : null;
            }
            if ($hall === null) {
                // Площадка без technical-зала: схема ещё не рисовалась,
                // продавать нечего — сеанс всё равно нужен для даты.
                $hall = Hall::query()->create([
                    'venue_id' => $event->venue_id,
                    'name' => $event->title,
                    'status' => 'active',
                ]);
            }

            $attributes = [
                'event_id' => $event->id,
                'venue_id' => $event->venue_id,
                'hall_id' => $hall->id,
                'schema_version_id' => $schemaVersionId,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'sales_start_at' => $event->sales_start_at,
                'sales_end_at' => $event->sales_end_at,
                'timezone' => $event->timezone ?? config('app.timezone', 'UTC'),
            ];

            $schemaChanged = false;
            if ($session === null) {
                $session = Session::query()->create($attributes);
                $schemaChanged = $schemaVersionId !== null;
            } else {
                $schemaChanged = (int) $session->schema_version_id !== (int) $schemaVersionId;
                $session->fill($attributes)->save();
            }

            $event->primary_session_id = $session->id;
            if ($schemaVersionId !== null) {
                $event->schema_version_id = $schemaVersionId;
            }
            $event->save();

            if ($schemaChanged && $schemaVersionId !== null) {
                $this->inventoryService->generateFromSchema(
                    HallSchemaVersion::query()->findOrFail($schemaVersionId),
                    $session,
                );
            }
        });
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

        $event = $this->eventRepository->update($event, $data);
        $this->syncPrimarySession($event, $data);

        return $event->fresh();
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
     *
     * Переход проверяется по `EventStateMachine`: `completed` достижим только из
     * `published`. Без проверки вызов на черновике записал бы статус, который
     * машина запрещает, — а `completed` входит в `EventStatus::publiclyVisible()`,
     * то есть черновик мгновенно стал бы публичным и попал бы в карту сайта.
     *
     * @throws ConflictError если переход запрещён
     */
    public function complete(Event $event): Event
    {
        if (! EventStateMachine::make()->can((string) $event->status, EventStatus::COMPLETED)) {
            throw new ConflictError(
                'This event cannot be marked as completed from its current status.',
                'EVENT_TRANSITION_NOT_ALLOWED',
                ['event_id' => $event->public_id, 'status' => (string) $event->status],
            );
        }

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
