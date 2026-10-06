<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Services;

use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Modules\Venues\Models\Venue;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersion as DomainSchemaVersion;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionPolicy;
use Nabilet\Modules\HallSchemas\Domain\VersionDecision;
use Nabilet\Modules\Venues\Halls\Domain\SchemaPayloadNormalizer;
use Nabilet\Modules\Venues\Halls\Repositories\HallRepository;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * P2 (архитектурный дуализм Policy): этот сервис использует ЕДИНСТВЕННУЮ
 * живую политику версий — `Nabilet\Modules\HallSchemas\Domain\SchemaVersionPolicy`
 * (словарь draft/published/archived, ТЗ §20/§46/§98). Старый статический
 * класс `Nabilet\Modules\Venues\Halls\Domain\SchemaVersionPolicy` со словарём
 * 'active' был мёртвой зависимостью (никем не вызывался) и удалён.
 */
class HallService
{
    public function __construct(
        protected HallRepository $repository,
        protected SchemaVersionPolicy $schemaPolicy
    ) {}

    /**
     * Eloquent-строка → доменный `SchemaVersion` (HallSchemas).
     *
     * Статусы в БД и в домене совпадают (ck_schema_status), поэтому конверсия
     * тривиальна; `hasPayload` — только «есть ли что публиковать», сам JSON
     * через домен не гоняется.
     */
    private function toDomain(HallSchemaVersion $version): DomainSchemaVersion
    {
        $payload = $version->schema_json ?? [];

        return new DomainSchemaVersion(
            (int) $version->id,
            (int) $version->hall_id,
            (int) $version->version,
            (string) $version->status,
            self::payloadHasSeats($payload),
            $version->published_at !== null
                ? \DateTimeImmutable::createFromInterface($version->published_at)
                : null,
        );
    }

    /**
     * Есть ли в схеме хоть одно продаваемое место/зона.
     *
     * Понимает оба формата payload (редакторский `sectors[].seats[]` и
     * БД-формат `sectors[].rows[].seats[]`), иначе легаси-схема прошла бы
     * проверку «пустоты» неверно.
     *
     * @param array<string, mixed>|null $payload
     */
    public static function payloadHasSeats(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        $sectors = $payload['sectors'] ?? [];
        if (is_array($sectors)) {
            foreach ($sectors as $sector) {
                if (!is_array($sector)) {
                    continue;
                }
                if (is_array($sector['seats'] ?? null) && count($sector['seats']) > 0) {
                    return true;
                }
                $rows = $sector['rows'] ?? [];
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (is_array($row) && is_array($row['seats'] ?? null) && count($row['seats']) > 0) {
                        return true;
                    }
                }
            }
        }

        // Стоячие зоны продаются без рядов — схема с одной фан-зоной не
        // считается пустой.
        //
        // Столы (`kind: 'table'`) здесь СОЗНАТЕЛЬНО не считаются продаваемыми.
        // Раньше считались — и это расходилось и с `toInventoryFormat()`, и с
        // самой схемой БД: `ck_inventory_type CHECK (type IN ('seat','standing'))`
        // (database/migrations/2026_09_20_001000_add_check_constraints.php:54)
        // не допускает позицию инвентаря типа «стол», а `ck_inventory_target`
        // требует у каждой позиции либо `seat_id`, либо `standing_zone_id`.
        // В редакторе зала стол — тоже только прямоугольник-декорация: у него
        // нет ни вместимости, ни цены (HallEditorPage.vue, `createStaticAt`).
        // Из-за этой асимметрии зал «только со столами» проходил проверку
        // непустоты, публиковался — и генератор инвентаря создавал 0 позиций:
        // витрина показывала зал, в котором нечего купить. Теперь такой payload
        // отвергается как пустой (SCHEMA_EMPTY_PAYLOAD), fail-closed.
        $staticObjects = $payload['staticObjects'] ?? [];
        if (is_array($staticObjects)) {
            foreach ($staticObjects as $obj) {
                if (is_array($obj) && ($obj['kind'] ?? null) === 'standing'
                    && (int) ($obj['capacity'] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Доменное решение политики → HTTP-ошибка в конверте §66.
     *
     * Коды ошибки стабильны (фронт ветвится на `code`, не на текст):
     * empty_payload → SCHEMA_EMPTY_PAYLOAD (422), остальные → 409.
     */
    private function refuse(VersionDecision $decision, string $fallbackCode): never
    {
        $reason = (string) $decision->reason();

        [$code, $status] = match ($reason) {
            SchemaVersionPolicy::REASON_EMPTY_PAYLOAD => ['SCHEMA_EMPTY_PAYLOAD', 422],
            SchemaVersionPolicy::REASON_FROZEN => ['SCHEMA_VERSION_IMMUTABLE', 409],
            SchemaVersionPolicy::REASON_ANOTHER_VERSION_PUBLISHED => ['SCHEMA_VERSION_ALREADY_PUBLISHED', 409],
            default => [$fallbackCode, 409],
        };

        // Ветка 422 — ValidationError: (errors, message, context, errorCode).
        // Ветка 409 — ConflictError: (message, errorCode, context).
        // Код обязан доехать до клиента: `AppError` обещает ветвление по `code`.
        if ($status === 422) {
            throw new ValidationError(
                ['schema' => [$decision->message()]],
                $decision->message(),
                ['reason' => $reason],
                $code,
            );
        }

        throw new ConflictError($decision->message(), $code, ['reason' => $reason]);
    }

    public function createHall(array $data): Hall
    {
        return $this->repository->create($data);
    }

    public function updateHall(Hall $hall, array $data): Hall
    {
        return $this->repository->update($hall, $data);
    }

    public function deleteHall(Hall $hall): bool
    {
        return DB::transaction(function () use ($hall): bool {
            $lockedHall = Hall::query()->whereKey($hall->id)->lockForUpdate()->first();
            if ($lockedHall === null) {
                return false;
            }

            // Share the hall lock with publish/archive so this check cannot race
            // a lifecycle transition and then cascade-delete a newly frozen map.
            if ($lockedHall->schemaVersions()->whereIn('status', ['published', 'archived'])->exists()) {
                throw new ConflictError(
                    'Нельзя удалить зал, пока у него есть опубликованные или архивные схемы.',
                    'HALL_HAS_FROZEN_SCHEMA_VERSIONS',
                );
            }

            return $this->repository->delete($lockedHall);
        });
    }

    /**
     * Read accessors.
     *
     * The controller used to call `$this->service->repository->...` directly.
     * `$repository` is protected, so every one of those calls was a fatal
     * "Cannot access protected property" — index() and show() returned 500 for
     * any hall. Reading through the service keeps the repository private and
     * gives one place to add scoping later.
     */
    /**
     * Залы площадки, адресуемой по `public_id`.
     *
     * Публичный идентификатор площадки, а не её BIGINT: путь приводится к int
     * до вызова контроллера, поэтому `GET /venues/1abc/halls` отдавал залы
     * площадки 1. Здесь площадка ищется строкой, мусор даёт 404.
     * В репозиторий уходит уже числовой FK — `halls.venue_id` им и является.
     */
    public function findByVenue(string $venuePublicId, int $limit = 15): LengthAwarePaginator
    {
        $venueId = Venue::query()->where('public_id', $venuePublicId)->value('id');

        if ($venueId === null) {
            throw new NotFoundError('Venue', $venuePublicId);
        }

        return $this->repository->findByVenue((int) $venueId, $limit);
    }

        /**
         * Все залы (для селекта в форме сеанса).
         */
        public function findAll(): LengthAwarePaginator
        {
            return $this->repository->paginate(100);
        }

    public function findByPublicId(string $publicId): ?Hall
    {
        return $this->repository->findByPublicId($publicId);
    }

    public function getSchemaVersions(Hall $hall): Collection
    {
        return $this->repository->getSchemaVersions($hall);
    }

    /**
     * Единственная опубликованная версия схемы зала (для публичной витрины, B10).
     */
    public function getPublishedSchemaVersion(Hall $hall): ?HallSchemaVersion
    {
        return HallSchemaVersion::query()
            ->where('hall_id', $hall->id)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();
    }

    public function createSchemaDraft(Hall $hall, array $payload, int $userId, ?int $versionId = null, ?int $expectedRevision = null): HallSchemaVersion
    {
        return DB::transaction(function () use ($hall, $payload, $userId, $versionId, $expectedRevision) {
            $this->assertPayloadSize($payload);
            $this->validateSchemaPayload($payload);

            // Нормализация формы — ПОСЛЕ валидации и ПЕРЕД записью. Валидатор
            // работает с PHP-массивами (для него `rowPrices` — всегда массив),
            // а в БД поле-карта обязано лежать JSON-объектом: без этого пустая
            // карта цен по рядам сохранялась как `[]` и форма поля начинала
            // зависеть от данных (§54).
            $payload = SchemaPayloadNormalizer::normalize($payload);

            // Serialise draft creation, draft edits, publish and archive per hall.
            // This also closes the race where two first autosaves both see no draft.
            $lockedHall = Hall::query()->whereKey($hall->id)->lockForUpdate()->first();
            if ($lockedHall === null) {
                throw new NotFoundError('Hall', (string) $hall->public_id);
            }

            if ($versionId !== null) {
                $target = $lockedHall->schemaVersions()
                    ->where('id', $versionId)
                    ->lockForUpdate()
                    ->first();
                if ($target === null) {
                    throw new NotFoundError('HallSchemaVersion', (string) $versionId);
                }

                $editDecision = $this->schemaPolicy->canEditPayload($this->toDomain($target));
                if (!$editDecision->isAllowed()) {
                    $this->refuse($editDecision, 'SCHEMA_VERSION_NOT_DRAFT');
                }

                // Existing rows predate the revision column and remain NULL by
                // design (the migration must not backfill/alter their records).
                // Treat that legacy state as revision 1; the first edit becomes 2.
                $currentRevision = $target->revision === null ? 1 : (int) $target->revision;
                if ($expectedRevision === null || $currentRevision !== $expectedRevision) {
                    throw new ConflictError(
                        'Черновик изменён другим редактором. Перезагрузите схему перед сохранением.',
                        'SCHEMA_DRAFT_CONFLICT',
                        [
                            'version_id' => $target->id,
                            'expected_revision' => $expectedRevision,
                            'current_revision' => $currentRevision,
                        ],
                    );
                }

                $target->update([
                    'schema_json' => $payload,
                    'revision' => $currentRevision + 1,
                ]);

                return $target->fresh();
            }

            // A concurrent first save may have created a draft since this editor
            // loaded. Never overwrite it without the version id + revision token.
            $existingDraft = $lockedHall->schemaVersions()
                ->where('status', 'draft')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($existingDraft !== null) {
                throw new ConflictError(
                    'Черновик уже создан другим редактором. Перезагрузите схему перед сохранением.',
                    'SCHEMA_DRAFT_CONFLICT',
                    ['version_id' => $existingDraft->id, 'current_revision' => $existingDraft->revision === null ? 1 : (int) $existingDraft->revision],
                );
            }

            return $this->repository->createSchemaVersion($lockedHall, $payload, 'draft');
        });
    }

    /**
     * Опубликовать черновик схемы зала.
     *
     * ПУБЛИЧНЫЙ API (B-scope): принимает internal-id версии и ОБЯЗАТЕЛЬНЫЙ
     * public_id зала, к которому версия должна принадлежать. Без проверки
     * принадлежности эндпоинт `/schema-versions/{id}/publish` позволял
     * опубликовать версию чужого зала, просто перебрав id (в админке id
     * автоинкрементные). Роут передаёт {publicId} из пути — то есть scope
     * задаётся залом, который оператор явно открыл.
     */
    public function publishSchemaVersion(int $versionId, int $userId, string $hallPublicId): HallSchemaVersion
    {
        return DB::transaction(function () use ($versionId, $userId, $hallPublicId) {
            // Locking the hall serialises lifecycle transitions so two drafts
            // cannot both pass the one-published-version policy concurrently.
            $hall = Hall::query()
                ->where('public_id', $hallPublicId)
                ->lockForUpdate()
                ->first();
            if ($hall === null) {
                throw new NotFoundError('Hall', $hallPublicId);
            }

            $version = $hall->schemaVersions()
                ->where('id', $versionId)
                ->lockForUpdate()
                ->first();
            if ($version === null) {
                throw new NotFoundError('HallSchemaVersion', (string) $versionId);
            }

            $siblings = $hall->schemaVersions()->lockForUpdate()->get();
            $published = $siblings->filter(
                static fn (HallSchemaVersion $sibling): bool => $sibling->status === 'published'
            )->values();

            // Replacing the live map is one atomic lifecycle operation. Retire
            // the sole current publication through the domain archive policy,
            // then evaluate the candidate against the now-archived sibling set.
            // Corrupt state with multiple live maps is not repaired silently.
            if ($published->count() > 1) {
                throw new ConflictError(
                    'У зала уже несколько опубликованных версий. Исправьте состояние вручную.',
                    'SCHEMA_MULTIPLE_PUBLISHED_VERSIONS',
                );
            }
            if ($published->isNotEmpty()) {
                /** @var HallSchemaVersion $previouslyPublished */
                $previouslyPublished = $published->first();
                $archiveDecision = $this->schemaPolicy->canArchive($this->toDomain($previouslyPublished));
                if (!$archiveDecision->isAllowed()) {
                    $this->refuse($archiveDecision, 'SCHEMA_VERSION_NOT_PUBLISHED');
                }
                $previouslyPublished->update(['status' => 'archived']);
            }

            $siblings = $siblings
                ->map(function (HallSchemaVersion $sibling) use ($published): HallSchemaVersion {
                    if ($published->contains('id', $sibling->id)) {
                        $sibling->status = 'archived';
                    }
                    return $sibling;
                })
                ->map(fn (HallSchemaVersion $sibling): DomainSchemaVersion => $this->toDomain($sibling))
                ->all();

            $decision = $this->schemaPolicy->canPublish($this->toDomain($version), $siblings);
            if (!$decision->isAllowed()) {
                $this->refuse($decision, 'SCHEMA_VERSION_NOT_DRAFT');
            }

            $this->validateSchemaPayload($version->schema_json ?? []);

            return $this->repository->publishSchemaVersion($version);
        });
    }

    /** Archive a version only when it belongs to the scoped hall. */
    public function archiveSchemaVersion(int $versionId, string $hallPublicId): HallSchemaVersion
    {
        return DB::transaction(function () use ($versionId, $hallPublicId) {
            $hall = Hall::query()
                ->where('public_id', $hallPublicId)
                ->lockForUpdate()
                ->first();
            if ($hall === null) {
                throw new NotFoundError('Hall', $hallPublicId);
            }

            $version = $hall->schemaVersions()
                ->where('id', $versionId)
                ->lockForUpdate()
                ->first();
            if ($version === null) {
                throw new NotFoundError('HallSchemaVersion', (string) $versionId);
            }

            $decision = $this->schemaPolicy->canArchive($this->toDomain($version));
            if (!$decision->isAllowed()) {
                $this->refuse($decision, 'SCHEMA_VERSION_NOT_PUBLISHED');
            }

            $version->update(['status' => 'archived']);

            return $version->fresh();
        });
    }

    /**
     * Ограничение размера схемы на сервере (P2). Считаем сериализованный
     * JSON, а не «на глаз» по массиву: именно байты уходят в колонку.
     */
    protected function assertPayloadSize(array $payload): void
    {
        $maxBytes = (int) (config('nabilet.hall_schema.max_payload_bytes') ?? 2_000_000);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $size = $json === false ? PHP_INT_MAX : strlen($json);

        if ($size > $maxBytes) {
            throw new ValidationError([
                'payload' => [sprintf(
                    'Схема слишком большая: %.1f MB (максимум %.1f MB). Удалите лишние сектора или загрузите фон отдельным файлом.',
                    $size / 1_048_576,
                    $maxBytes / 1_048_576,
                )],
            ], 'Hall schema payload too large');
        }
    }

    /**
     * Server-side schema validation (A7, ТЗ §49/§50).
     *
     * The editor validates prices before writing them to the model, but that
     * is UI sugar: curl with an admin token can post anything to the draft
     * endpoint. This is the real boundary. Prices are minor units (kopecks):
     * must be integers >= 1 — zero/negative/NaN/float/garbage are rejected.
     * Failures surface as ValidationError → 422 in the §66 envelope with a
     * per-field `details.fields` map, so the client can point at the sector.
     *
     * Empty payload (no sectors yet) is legal for drafts: the editor starts
     * from an empty canvas and autosaves before any sector exists. Full
     * structure checks still run on whatever IS present.
     *
     * @param array<string, mixed> $payload
     */
    protected function validateSchemaPayload(array $payload): void
    {
        $errors = [];

        // Canvas dimensions, when present, must be sane positive integers.
        if (array_key_exists('canvas', $payload) && !is_array($payload['canvas'])) {
            $errors['canvas'] = ['canvas must be an object'];
        }
        $canvas = is_array($payload['canvas'] ?? null) ? $payload['canvas'] : [];
        foreach (['width', 'height'] as $dim) {
            if (array_key_exists($dim, $canvas) && !$this->isPositiveInt($canvas[$dim])) {
                $errors["canvas.$dim"] = ["canvas.$dim must be a positive integer"];
            }
        }

        // Принимаем любой объект с секторами (форматы редактора и импорта могут
        // отличаться: редактор — sectors[].seats, БД-формат — sectors[].rows[]).
        if (array_key_exists('sectors', $payload) && !is_array($payload['sectors'])) {
            $errors['sectors'] = ['sectors must be an array'];
        }
        $sectors = is_array($payload['sectors'] ?? null) ? $payload['sectors'] : [];

        $globalSeatIds = [];
        // Overlap is a property of the CANVAS, so seat positions must be
        // compared in canvas space across every sector of the schema.
        //
        // Редактор (HallEditorPage.vue) хранит координаты мест ЛОКАЛЬНО внутри
        // группы сектора (`Konva.Group({ x: sector.x, y: sector.y })`, места —
        // `seat.x/seat.y`), а смещение сектора — в `sector.x` / `sector.y`.
        // Проверка по «сырым» координатам сравнивала локальные системы разных
        // секторов: два сектора, разнесённых по холсту, имеют одинаковые
        // локальные координаты (обычно оба начинаются с (0,0)), и любой зал из
        // ≥2 секторов отвергался как «Seats must not overlap» — конструктор
        // залов не мог сохранить ни одну реальную конфигурацию.
        $positionBuckets = [];
        foreach ($sectors as $i => $sector) {
            if (!is_array($sector)) {
                $errors["sectors.$i"] = ['sector must be an object'];
                continue;
            }

            // Смещение группы сектора на холсте. Отсутствие x/y — легаси/импорт:
            // считаем, что места уже в координатах холста (offset 0).
            $sectorOffsetX = is_numeric($sector['x'] ?? null) ? (float) $sector['x'] : 0.0;
            $sectorOffsetY = is_numeric($sector['y'] ?? null) ? (float) $sector['y'] : 0.0;

            if (!isset($sector['name']) || !is_string($sector['name']) || trim($sector['name']) === '') {
                $errors["sectors.$i.name"] = ['Each sector must have a non-empty name'];
            }
            foreach (['seats', 'rows'] as $collectionKey) {
                if (array_key_exists($collectionKey, $sector) && !is_array($sector[$collectionKey])) {
                    $errors["sectors.$i.$collectionKey"] = ["$collectionKey must be an array"];
                }
            }

            // Редакторский формат: цена сектора в минорных единицах (§50).
            $sectorName = (string) ($sector['name'] ?? '(unnamed)');
            if (array_key_exists('priceMinor', $sector)) {
                $pm = $sector['priceMinor'];
                if ($pm !== null && !$this->isPositiveInt($pm)) {
                    $errors["sectors.$i.priceMinor"] = [
                        "Sector '{$sectorName}' priceMinor must be an integer number of kopecks greater than zero",
                    ];
                }
            } elseif (array_key_exists('price', $sector)) {
                // Легаси-поле (сидеры/импорт): целые копейки, > 0.
                $p = $sector['price'];
                if ($p !== null && !$this->isPositiveInt($p)) {
                    $errors["sectors.$i.price"] = [
                        "Sector '{$sectorName}' price must be an integer number of kopecks greater than zero",
                    ];
                }
            }

            // Цены по рядам (§50): rowPrices перекрывает цену сектора.
            if (array_key_exists('rowPrices', $sector) && !is_array($sector['rowPrices'])) {
                $errors["sectors.$i.rowPrices"] = ['rowPrices must be an object of row prices'];
            }
            $rowPrices = is_array($sector['rowPrices'] ?? null) ? $sector['rowPrices'] : [];
            foreach ($rowPrices as $row => $rp) {
                if ($rp !== null && !$this->isPositiveInt($rp)) {
                    $errors["sectors.$i.rowPrices.$row"] = [
                        "Row {$row} price must be an integer number of kopecks greater than zero",
                    ];
                }
            }

            // Редакторский формат: идентификаторы, нумерация, геометрия и цены
            // валидируются на сервере; браузерные проверки не являются границей.
            $editorSeats = is_array($sector['seats'] ?? null) ? $sector['seats'] : [];
            $seatNumbers = [];
            foreach ($editorSeats as $j => $seat) {
                if (!is_array($seat)) {
                    $errors["sectors.$i.seats.$j"] = ['Seat must be an object'];
                    continue;
                }

                $rowNo = $seat['row'] ?? 1;
                $seatNo = $seat['number'] ?? null;
                if (!$this->isPositiveInt($rowNo)) {
                    $errors["sectors.$i.seats.$j.row"] = ['Seat row must be a positive integer'];
                }
                if (!$this->isPositiveInt($seatNo)) {
                    $errors["sectors.$i.seats.$j.number"] = ['Seat number must be a positive integer'];
                } elseif ($this->isPositiveInt($rowNo)) {
                    $numberKey = (string) $rowNo . ':' . (string) $seatNo;
                    if (isset($seatNumbers[$numberKey])) {
                        $errors["sectors.$i.seats.$j.number"] = ['Seat number must be unique within its row'];
                    }
                    $seatNumbers[$numberKey] = true;
                }

                if ($this->isPositiveInt($rowNo)) {
                    $effectivePrice = $rowPrices[(string) $rowNo]
                        ?? $rowPrices[$rowNo]
                        ?? $sector['priceMinor']
                        ?? $sector['price']
                        ?? null;
                    if (!$this->isPositiveInt($effectivePrice)) {
                        $errors["sectors.$i.seats.$j.priceMinor"] = [
                            'Every sellable seat must resolve to a positive integer price in minor units',
                        ];
                    }
                }

                if (isset($seat['id']) && is_string($seat['id']) && $seat['id'] !== '') {
                    if (isset($globalSeatIds[$seat['id']])) {
                        $errors["sectors.$i.seats.$j.id"] = ['Seat id must be unique within the schema'];
                    }
                    $globalSeatIds[$seat['id']] = true;
                }

                foreach (['x', 'y'] as $coordinate) {
                    if (array_key_exists($coordinate, $seat)
                        && ((!is_int($seat[$coordinate]) && !is_float($seat[$coordinate]))
                            || !is_finite((float) $seat[$coordinate])
                            || abs((float) $seat[$coordinate]) > 1_000_000)) {
                        $errors["sectors.$i.seats.$j.$coordinate"] = ["Seat $coordinate must be a finite, bounded number"];
                    }
                }

                if (array_key_exists('priceMinor', $seat)
                    && $seat['priceMinor'] !== null && !$this->isPositiveInt($seat['priceMinor'])) {
                    $errors["sectors.$i.seats.$j.priceMinor"] = ['Seat priceMinor must be an integer greater than zero'];
                }

                // Editor canvas coordinates are pixels and seats are 16px wide.
                // Spatial buckets keep this check linear for large seat maps.
                if (isset($seat['x'], $seat['y'])
                    && is_numeric($seat['x']) && is_numeric($seat['y'])
                    && is_finite((float) $seat['x']) && is_finite((float) $seat['y'])) {
                    // Локальные координаты места + смещение группы = координаты холста.
                    $x = (float) $seat['x'] + $sectorOffsetX;
                    $y = (float) $seat['y'] + $sectorOffsetY;
                    $cellX = (int) floor($x / 16);
                    $cellY = (int) floor($y / 16);
                    for ($dx = -1; $dx <= 1; $dx++) {
                        for ($dy = -1; $dy <= 1; $dy++) {
                            foreach ($positionBuckets[($cellX + $dx) . ':' . ($cellY + $dy)] ?? [] as [$otherX, $otherY]) {
                                if (abs($x - $otherX) < 16 && abs($y - $otherY) < 16) {
                                    $errors["sectors.$i.seats.$j.position"] = ['Seats must not overlap'];
                                    break 3;
                                }
                            }
                        }
                    }
                    $positionBuckets[$cellX . ':' . $cellY][] = [$x, $y];
                }
            }

            // БД-формат: уникальные номера рядов/мест, IDs и цены в минорных единицах.
            $rows = is_array($sector['rows'] ?? null) ? $sector['rows'] : [];
            $rowNumbers = [];
            foreach ($rows as $r => $row) {
                if (!is_array($row)) {
                    $errors["sectors.$i.rows.$r"] = ['Row must be an object'];
                    continue;
                }
                $rowNo = $row['number'] ?? '?';
                $rowKey = is_scalar($rowNo) ? trim((string) $rowNo) : '';
                if ($rowKey === '') {
                    $errors["sectors.$i.rows.$r.number"] = ['Row number must be a non-empty value'];
                } elseif (isset($rowNumbers[$rowKey])) {
                    $errors["sectors.$i.rows.$r.number"] = ['Row numbers must be unique within a sector'];
                }
                $rowNumbers[$rowKey] = true;

                if (array_key_exists('price_amount', $row)
                    && $row['price_amount'] !== null && !$this->isPositiveInt($row['price_amount'])) {
                    $errors["sectors.$i.rows.$rowNo.price_amount"] = [
                        "Row {$rowNo} price_amount must be an integer number of kopecks greater than zero",
                    ];
                }
                if (array_key_exists('seats', $row) && !is_array($row['seats'])) {
                    $errors["sectors.$i.rows.$rowNo.seats"] = ['Seats must be an array'];
                    continue;
                }
                $rowSeats = is_array($row['seats'] ?? null) ? $row['seats'] : [];
                if ($rowSeats !== [] && !$this->isPositiveInt($row['price_amount'] ?? null)) {
                    $errors["sectors.$i.rows.$rowNo.price_amount"] = [
                        'Every sellable row must have a positive integer price_amount in minor units',
                    ];
                }

                $rowSeatNumbers = [];
                foreach ($rowSeats as $j => $seat) {
                    if (!is_array($seat)) {
                        $errors["sectors.$i.rows.$rowNo.seats.$j"] = ['Seat must be an object'];
                        continue;
                    }

                    $seatId = $seat['id'] ?? null;
                    if ((is_string($seatId) || is_int($seatId)) && trim((string) $seatId) !== '') {
                        $seatIdKey = (string) $seatId;
                        if (isset($globalSeatIds[$seatIdKey])) {
                            $errors["sectors.$i.rows.$rowNo.seats.$j.id"] = ['Seat id must be unique within the schema'];
                        }
                        $globalSeatIds[$seatIdKey] = true;
                    }

                    $seatNoValue = $seat['number'] ?? null;
                    $isStandingSeat = ($seat['type'] ?? null) === 'standing';
                    if (!$isStandingSeat || ($seatNoValue !== null && trim((string) $seatNoValue) !== '')) {
                        $seatNo = trim((string) ($seatNoValue ?? ''));
                        if ($seatNo === '' || !preg_match('/^[1-9][0-9]*$/', $seatNo)) {
                            $errors["sectors.$i.rows.$rowNo.seats.$j.number"] = ['Seat number must be a positive integer'];
                        } elseif (isset($rowSeatNumbers[$seatNo])) {
                            $errors["sectors.$i.rows.$rowNo.seats.$j.number"] = ['Seat numbers must be unique within a row'];
                        }
                        $rowSeatNumbers[$seatNo] = true;
                    }
                    foreach (['price_amount', 'price'] as $key) {
                        if (array_key_exists($key, $seat)
                            && $seat[$key] !== null && !$this->isPositiveInt($seat[$key])) {
                            $errors["sectors.$i.rows.$rowNo.seats.$j.$key"] = [
                                "Seat {$key} must be an integer number of kopecks greater than zero",
                            ];
                        }
                    }
                }
            }
        }

        if (array_key_exists('staticObjects', $payload) && !is_array($payload['staticObjects'])) {
            $errors['staticObjects'] = ['staticObjects must be an array'];
        }
        $staticObjects = is_array($payload['staticObjects'] ?? null) ? $payload['staticObjects'] : [];
        foreach ($staticObjects as $i => $object) {
            if (!is_array($object)) {
                $errors["staticObjects.$i"] = ['Static object must be an object'];
                continue;
            }
            if (($object['kind'] ?? null) !== 'standing') {
                continue;
            }

            $capacity = $object['capacity'] ?? null;
            if (!$this->isPositiveInt($capacity)) {
                $errors["staticObjects.$i.capacity"] = ['A sellable standing zone requires a positive integer capacity'];
                continue;
            }
            $price = $object['priceMinor'] ?? $object['price'] ?? null;
            if (!$this->isPositiveInt($price)) {
                $errors["staticObjects.$i.priceMinor"] = ['A sellable standing zone requires a positive integer price in minor units'];
            }
        }

        if ($errors !== []) {
            throw new ValidationError($errors, 'Hall schema validation failed');
        }
    }

    /** Strictly a positive JSON integer; floats and numeric strings are not money/counts. */
    private function isPositiveInt(mixed $v): bool
    {
        return is_int($v) && $v >= 1;
    }
}
