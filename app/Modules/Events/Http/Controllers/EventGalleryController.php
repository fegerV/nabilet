<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Media\Domain\MediaEntityType;
use Nabilet\Modules\Media\Domain\MediaRole;
use Nabilet\Modules\Media\Http\Resources\MediaLinkResource;
use Nabilet\Modules\Media\Services\MediaService;

/**
 * Дополнительные фото и видео мероприятия.
 *
 * ПОЧЕМУ ЭТОТ КОНТРОЛЛЕР ЖИВЁТ В EVENTS, А НЕ В MEDIA
 *
 * Модуль Media не знает, что такое мероприятие: у него нет ни модели `Event`,
 * ни таблицы `events`, ни представления о том, что у мероприятия есть сеансы.
 * Он умеет одно — привязать файл к паре «тип объекта + идентификатор». Это
 * ровно то, что делает `media_links` для всех видов объектов сразу.
 *
 * Поэтому «галерея мероприятия» — это тонкая обёртка в модуле Events: она
 * проверяет, что мероприятие существует и принадлежит организации запроса, и
 * делегирует привязку в `MediaService`. Обратный вариант — завести в Media
 * маршруты вида `/events/{id}/gallery` — заставил бы модуль знать про
 * мероприятия, и следующий модуль (площадки, залы) потребовал бы правки в
 * чужом модуле. Здесь же добавление галереи к площадке — новый контроллер в
 * Venues, а Media не меняется вообще.
 *
 * ЗАГРУЗКА ФАЙЛА — ЧЕРЕЗ POST /media
 *
 * Здесь только ПРИВЯЗКА уже существующего файла (`media_id`). Загрузка — это
 * `POST /media` с `entity_type=event` и `entity_id={id}`: одна операция вместо
 * двух, и файл с привязкой создаются в одной транзакции. Дублировать загрузку
 * ещё и здесь значило бы завести второй путь к тому же результату, и они
 * разошлись бы — как разошлись два канала отправки письма с билетом.
 */
class EventGalleryController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * Галерея мероприятия, в порядке `position`.
     *
     * Без пагинации: галерея — это список, который администратор целиком видит
     * и переставляет. Пагинация по 20 элементов сделала бы перетаскивание через
     * границу страницы невозможным, а число дополнительных фото у мероприятия
     * измеряется десятками, а не тысячами.
     */
    public function index(Event $event): JsonResponse
    {
        $this->assertBelongsToOrganization($event);

        $links = $this->media->forEntity(
            MediaEntityType::EVENT,
            (int) $event->id,
            MediaRole::GALLERY
        );

        return response()->json([
            'data' => MediaLinkResource::collection($links),
            'meta' => ['total' => $links->count()],
        ]);
    }

    /**
     * Привязать существующий файл к мероприятию.
     *
     * `position` необязателен: без него файл встаёт в конец (см.
     * `MediaService::attach()`). Повторный вызов с тем же файлом идемпотентен —
     * это `updateOrCreate` по уникальному ключу `uq_media_links`, а не вставка.
     */
    public function store(Request $request, Event $event): JsonResponse
    {
        $this->assertBelongsToOrganization($event);

        $data = $request->validate([
            'media_id' => ['required', 'integer', 'min:1'],
            'role' => ['sometimes', 'nullable', 'string', 'max:' . MediaRole::MAX_LENGTH],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        // Файл ищется в пределах организации запроса: чужой `media_id` даёт 404,
        // а не 403 — 403 подтвердил бы существование файла другой организации.
        $asset = $this->media->findForOrganization(
            (int) $data['media_id'],
            (int) $event->organization_id
        );

        $link = $this->media->attach(
            $asset,
            MediaEntityType::EVENT,
            (int) $event->id,
            $this->role($data),
            isset($data['position']) ? (int) $data['position'] : null,
        );

        $link->setRelation('mediaAsset', $asset);

        return response()->json(['data' => new MediaLinkResource($link)], 201);
    }

    /**
     * Убрать файл из галереи мероприятия.
     *
     * Снимается ТОЛЬКО роль `gallery` (или явно указанная `?role=`). Снять все
     * роли сразу — значит заодно убрать афишу мероприятия, если тот же файл
     * назначен и афишей: администратор нажал «убрать из галереи», а получил
     * мероприятие без постера. Роль — часть уникального ключа связи, поэтому
     * разделение здесь бесплатное.
     *
     * Файл в хранилище НЕ удаляется: он мог быть привязан к другому
     * мероприятию, и удалять его здесь — значит ломать чужую галерею. Для
     * удаления самого файла есть `DELETE /media/{id}`.
     */
    public function destroy(Request $request, Event $event, int $media): JsonResponse
    {
        $this->assertBelongsToOrganization($event);

        $asset = $this->media->findForOrganization($media, (int) $event->organization_id);

        $role = $request->query('role');
        $role = is_string($role) && $role !== '' ? $role : MediaRole::GALLERY;

        $removed = $this->media->detach($asset, MediaEntityType::EVENT, (int) $event->id, $role);

        if ($removed === 0) {
            // Связи не было — это 404, а не 204. Иначе повторное удаление
            // выглядело бы успешным, и клиент не отличил бы «убрал» от «файла
            // никогда не было в галерее».
            throw new NotFoundError('MediaLink', sprintf('event:%d media:%d role:%s', $event->id, $media, $role));
        }

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function role(array $data): string
    {
        $role = $data['role'] ?? null;

        return is_string($role) && $role !== '' ? $role : MediaRole::GALLERY;
    }

    /**
     * Мероприятие принадлежит организации запроса.
     *
     * Неявная привязка модели (`{event}` → `Event`) ищет по `id` без учёта
     * организации, поэтому без этой проверки администратор одной организации
     * мог бы править галерею мероприятия другой, зная его номер. Чужое
     * мероприятие — 404: 403 подтвердил бы, что оно существует.
     *
     * Организация берётся у САМОГО мероприятия (`events.organization_id`,
     * NOT NULL) — это надёжнее контекста запроса, который в этом проекте не
     * связан как singleton и может оказаться пустым.
     */
    private function assertBelongsToOrganization(Event $event): void
    {
        $organizationId = $this->organizationId();

        if ((int) $event->organization_id !== $organizationId) {
            throw new NotFoundError('Event', (string) $event->id);
        }
    }

    private function organizationId(): int
    {
        $contextId = $this->context->tryId();

        if ($contextId !== null && ctype_digit((string) $contextId)) {
            return (int) $contextId;
        }

        return (int) (\Nabilet\Modules\Core\Organizations\Models\Organization::query()
            ->orderBy('id')
            ->value('id') ?? 0);
    }
}
