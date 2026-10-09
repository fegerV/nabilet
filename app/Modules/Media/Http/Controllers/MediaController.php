<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Media\Domain\MediaEntityType;
use Nabilet\Modules\Media\Domain\MediaRole;
use Nabilet\Modules\Media\Http\Resources\MediaAssetResource;
use Nabilet\Modules\Media\Models\MediaAsset;
use Nabilet\Modules\Media\Services\MediaService;

/**
 * Файлы: список, загрузка, метаданные, удаление.
 *
 * ДО ЭТОГО КОНТРОЛЛЕРА МОДУЛЯ НЕ БЫЛО
 *
 * `app/Modules/Media/` содержал два файла: `module.json` и провайдер, который в
 * `boot()` вызывал `loadRoutesFrom(__DIR__ . '/../routes/api.php')` — против
 * каталога `routes/`, которого не существует. Провайдер не был зарегистрирован
 * в `bootstrap/providers.php`, поэтому `loadRoutesFrom` не выполнялся ни разу, и
 * ошибка была не видна. Таблицы `media_assets` и `media_links` при этом
 * существовали с самого начала и были пусты: схема была, доступа не было.
 *
 * ГДЕ ПУБЛИЧНЫЙ ДОСТУП, А ГДЕ АДМИНСКИЙ
 *
 * Чтение требует `auth:api` (как и весь контракт: `bearerAuth` на всех пяти
 * операциях). Запись требует ещё и `admin`: файл — это афиша, план зала или
 * логотип, то есть то, что видит каждый покупатель. Ошибка здесь видна всем
 * сразу, а «случайный» не-административный загрузчик в проекте не нужен —
 * витрина ничего не загружает, она только читает.
 */
class MediaController extends Controller
{
    /**
     * Общий список типов для `POST /media`: афиши, галерея, документы, видео.
     *
     * ЧТО ЗДЕСЬ БЫЛО НЕ ТАК
     *
     * Список не содержал `mp4` и `webm`, хотя раздел «Дополнительные фото и
     * видео» в карточке мероприятия принимает их у себя в `accept`, пропускает
     * через свою проверку и рисует на витрине как `<video>`. Покупатель
     * администратора доходил до сервера и получал 422 «должен быть одного из
     * типов: jpg, jpeg, …» — то есть интерфейс обещал то, чего сервер не
     * принимал. Проверено живой загрузкой mp4: 422.
     *
     * Комментарий здесь раньше утверждал, что список совпадает с `poster_file`
     * формы мероприятия. Это неверно и было неверно всегда: `poster_file` —
     * `image` + `mimes:jpg,jpeg,png,webp` + 5 МБ, потому что афиша обязана быть
     * картинкой. Здесь же общий список модуля, поэтому он шире и намеренно
     * включает документы (`pdf`, `svg`) и видео.
     *
     * `GALLERY_TYPES` на фронтенде — осознанное ПОДмножество этого списка: без
     * `pdf` и `svg`, потому что в галерее мероприятия им не место.
     */
    private const ALLOWED_MIMES = 'jpg,jpeg,png,webp,avif,gif,svg,pdf,mp4,webm';

    private const MAX_SIZE_KB = 10240;

    public function __construct(
        private readonly MediaService $media,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * Список файлов организации.
     *
     * Фильтры `entity_type` + `entity_id` превращают эндпоинт в «галерею
     * объекта»: `GET /media?entity_type=event&entity_id=42`. Это тот же
     * эндпоинт, что и общий список, — отдельный путь для галереи завёл бы
     * второй способ делать то же самое, и они разошлись бы.
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();

        $perPage = $this->perPage($request);
        $entityType = $request->query('entity_type');
        $entityId = $request->query('entity_id');

        // `has()` и `filled()`, а не `!== null`: `?entity_id=` в строке запроса
        // приходит пустой строкой, и фильтр «по объекту 0» вернул бы пустой
        // список вместо галереи.
        if ($request->filled('entity_type') && $request->filled('entity_id') && is_string($entityType) && $entityType !== '') {
            $entityIdInt = (int) $entityId;

            $links = $this->media->forEntity(
                $entityType,
                $entityIdInt,
                $request->filled('role') ? (string) $request->query('role') : null
            );

            $assets = $links
                ->map(static fn ($link) => $link->mediaAsset)
                ->filter()
                ->values();

            // Связи догружаются и в этом ответе: библиотека файлов показывает,
            // к каким объектам привязан файл, и без `links` администратор решал
            // бы, удалять ли файл, не видя, что от него зависит. Один запрос на
            // всю страницу, а не по одному на файл.
            $assets->load('links');

            // Постраничность применяется к уже собранной коллекции: связи
            // объекта — это список, а не выборка, и `paginate()` на нём
            // потребовал бы второго запроса к `media_links` с тем же фильтром.
            $page = max(1, (int) $request->query('page', '1'));
            $total = $assets->count();
            $slice = $assets->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => MediaAssetResource::collection($slice),
                // Ключ — `current_page`, как в остальных списках и в типе
                // `PageMeta` витрины. Здесь стояло `page`: контракт объявлял
                // именно его, и этот контроллер был единственным, кто контракт
                // соблюдал, — то есть расходился с семью другими. Прав оказался
                // код, а не документ: `current_page` — форма Laravel
                // (`LengthAwarePaginator::toArray()`), её и читает фронтенд.
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
            ]);
        }

        $query = MediaAsset::query()
            // `links` нужны списку, чтобы показать, к чему привязан файл: без них
            // администратор удаляет файл вслепую. Это один дополнительный запрос
            // на страницу (`with`), а не запрос на каждый файл.
            ->with('links')
            ->where(function ($builder) use ($organizationId): void {
                $builder->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => MediaAssetResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $media): JsonResponse
    {
        $asset = $this->media->findForOrganization($media, $this->organizationId());
        $asset->load('links');

        return response()->json(['data' => new MediaAssetResource($asset)]);
    }

    /**
     * Загрузить файл или зарегистрировать уже существующий.
     *
     * ДВА ФОРМАТА ЗАПРОСА, И ЭТО НЕ НЕРЕШИТЕЛЬНОСТЬ
     *
     * Контракт описывает `application/json` с обязательными `filename`,
     * `mime_type`, `path` — то есть регистрацию файла, который уже где-то лежит.
     * Реальный администратор загружает файл из браузера, то есть
     * `multipart/form-data` с самим файлом. Поддержаны оба: ветка выбирается по
     * наличию поля `file`, а не по заголовку `Content-Type`, потому что
     * `Content-Type` может быть подделан клиентом, а наличие файла — нет.
     *
     * Привязка к объекту (`entity_type` + `entity_id`) необязательна, но если
     * задана, файл и связь создаются В ОДНОЙ транзакции: файл, загруженный в
     * галерею мероприятия и потерявший связь из-за ошибки, — это мусор в
     * хранилище, который никто не найдёт.
     */
    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();

        // Поля привязки валидируются ЗДЕСЬ, а не в ветках ниже, и это не
        // формальность: без них `entity_type` длиной 101 символ доходил до
        // вставки в `media_links.entity_type VARCHAR(100)`, и MySQL в строгом
        // режиме отвечал ошибкой 1406 → 500. Клиент должен получить 422 с
        // указанием поля, а не «Internal Server Error» на своей же опечатке.
        $attach = $request->validate([
            'entity_type' => ['sometimes', 'nullable', 'string', 'max:' . MediaEntityType::MAX_LENGTH],
            'entity_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'role' => ['sometimes', 'nullable', 'string', 'max:' . MediaRole::MAX_LENGTH],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $asset = $request->hasFile('file')
            ? $this->storeUploaded($request, $organizationId)
            : $this->storeRegistered($request, $organizationId);

        $this->attachIfRequested($attach, $asset);

        $asset->load('links');

        return response()->json(['data' => new MediaAssetResource($asset)], 201);
    }

    /**
     * Обновить метаданные и/или положение в галерее.
     *
     * `role` и `position` в схеме `MediaAssetUpdate` — это НЕ колонки
     * `media_assets`, а поля связи. Так их и трактуем: если запрос задаёт
     * `entity_type` + `entity_id`, обновляем связь; если нет, но файл привязан
     * ровно к одному объекту, обновляем её (админка правит карточку в галерее и
     * не обязана помнить, к какому объекту файл привязан). Неоднозначность
     * (файл привязан к нескольким объектам и объект не указан) — 422, а не
     * «обновим первую попавшуюся»: молчаливая правка чужой связи хуже отказа.
     */
    public function update(Request $request, int $media): JsonResponse
    {
        $asset = $this->media->findForOrganization($media, $this->organizationId());

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'variants_json' => ['sometimes', 'nullable', 'array'],
            'entity_type' => ['sometimes', 'nullable', 'string', 'max:' . MediaEntityType::MAX_LENGTH],
            'entity_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'role' => ['sometimes', 'nullable', 'string', 'max:' . MediaRole::MAX_LENGTH],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $this->media->update($asset, $data);

        if (array_key_exists('role', $data) || array_key_exists('position', $data)) {
            $this->applyLinkUpdate($asset, $data);
        }

        $asset->load('links');

        return response()->json(['data' => new MediaAssetResource($asset->fresh() ?? $asset)]);
    }

    /**
     * Удалить файл.
     *
     * Ответ `204` без тела — как в контракте. Отдавать `{deleted: true}` здесь
     * не нужно: код ответа уже несёт эту информацию.
     */
    public function destroy(int $media): JsonResponse
    {
        $asset = $this->media->findForOrganization($media, $this->organizationId());
        $this->media->delete($asset);

        return response()->json(null, 204);
    }

    private function storeUploaded(Request $request, int $organizationId): MediaAsset
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:' . self::ALLOWED_MIMES,
                'max:' . self::MAX_SIZE_KB,
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return $this->media->store($request->file('file'), [
            'organization_id' => $organizationId,
            'title' => $data['title'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
            'uploaded_by' => $this->userId(),
        ]);
    }

    private function storeRegistered(Request $request, int $organizationId): MediaAsset
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:150'],
            'size_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'width' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'checksum' => ['sometimes', 'nullable', 'string', 'size:64'],
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'variants_json' => ['sometimes', 'nullable', 'array'],
            'disk' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return $this->media->register($data + [
            'organization_id' => $organizationId,
            'uploaded_by' => $this->userId(),
        ]);
    }

    /**
     * Привязать только что созданный файл, если запрос это просил.
     *
     * Оба поля обязательны вместе: `entity_type` без `entity_id` не описывает
     * объект, и привязка «к типу вообще» смысла не имеет.
     *
     * @param  array<string, mixed>  $attach  уже провалидированные поля привязки
     */
    private function attachIfRequested(array $attach, MediaAsset $asset): void
    {
        $entityType = $attach['entity_type'] ?? null;
        $entityId = $attach['entity_id'] ?? null;

        if (! is_string($entityType) || $entityType === '' || $entityId === null) {
            return;
        }

        $role = $attach['role'] ?? null;

        $this->media->attach(
            $asset,
            $entityType,
            (int) $entityId,
            is_string($role) && $role !== '' ? $role : MediaRole::DEFAULT,
            isset($attach['position']) ? (int) $attach['position'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyLinkUpdate(MediaAsset $asset, array $data): void
    {
        $entityType = $data['entity_type'] ?? null;
        $entityId = $data['entity_id'] ?? null;

        if (! is_string($entityType) || $entityType === '' || $entityId === null) {
            $links = $asset->links()->get();

            // Ни одной связи — обновлять нечего, и это не ошибка: администратор
            // мог прислать `position` для файла, который ни к чему не привязан.
            if ($links->isEmpty()) {
                return;
            }

            $entities = $links
                ->map(static fn ($link): string => $link->entity_type . ':' . $link->entity_id)
                ->unique()
                ->values();

            if ($entities->count() > 1) {
                throw new ValidationError(
                    ['entity_id' => [
                        'Файл привязан к нескольким объектам (' . $entities->implode(', ')
                        . '). Укажите entity_type и entity_id, чтобы изменить нужную связь.',
                    ]],
                    'Неоднозначная связь файла.'
                );
            }

            $entityType = (string) $links->first()->entity_type;
            $entityId = (int) $links->first()->entity_id;
        }

        $role = $data['role'] ?? null;

        $query = $asset->links()
            ->where('entity_type', $entityType)
            ->where('entity_id', (int) $entityId);

        // Без явной роли правим связь в роли по умолчанию, а не все сразу:
        // «переставить в галерее» не должно переставлять афишу.
        $query->where('role', is_string($role) && $role !== '' ? $role : MediaRole::DEFAULT);

        $changes = array_filter([
            'role' => is_string($role) && $role !== '' ? $role : null,
            'position' => array_key_exists('position', $data) && $data['position'] !== null
                ? (int) $data['position']
                : null,
        ], static fn ($value): bool => $value !== null);

        // Пустой набор — это `update([])`, то есть SQL без SET-части и ошибка
        // синтаксиса. Такое приходит от PATCH с одной только `entity_id`: связь
        // найдена, менять в ней нечего. Выходим, а не формируем битый запрос.
        if ($changes === []) {
            return;
        }

        $query->update($changes);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', '24');

        // Верхняя граница обязательна: без неё `?per_page=1000000` — это способ
        // положить сервер одним запросом, который выглядит безобидно.
        return max(1, min(100, $perPage));
    }

    /**
     * Организация запроса.
     *
     * НЕ полагается на `OrganizationContext::id()`: `NabiletServiceProvider` не
     * зарегистрирован в `bootstrap/providers.php`, поэтому контекст не связан
     * как singleton и `app(OrganizationContext::class)` даёт каждый раз НОВЫЙ
     * экземпляр. Фолбэк «первая организация по id» — тот же приём, что в
     * `TicketTemplateController::organizationId()`.
     */
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

    private function userId(): ?int
    {
        $id = auth('api')->id();

        return $id === null ? null : (int) $id;
    }
}
