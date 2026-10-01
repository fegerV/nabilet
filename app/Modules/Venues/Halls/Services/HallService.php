<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Services;

use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersion as DomainSchemaVersion;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionPolicy;
use Nabilet\Modules\HallSchemas\Domain\VersionDecision;
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

        foreach (($payload['sectors'] ?? []) as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            if (is_array($sector['seats'] ?? null) && count($sector['seats']) > 0) {
                return true;
            }
            foreach (($sector['rows'] ?? []) as $row) {
                if (is_array($row) && is_array($row['seats'] ?? null) && count($row['seats']) > 0) {
                    return true;
                }
            }
        }

        // Standing-зоны и столы продаются без рядов — схема с одной фан-зоной
        // не считается пустой.
        foreach (($payload['staticObjects'] ?? []) as $obj) {
            if (is_array($obj) && in_array($obj['kind'] ?? null, ['standing', 'table'], true)) {
                return true;
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

        throw new ($status === 422 ? ValidationError::class : ConflictError::class)(
            $status === 422
                ? ['schema' => [$decision->message()]]
                : $decision->message(),
            $code,
            ['reason' => $reason],
        );
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
        return $this->repository->delete($hall);
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
    public function findByVenue(int $venueId, int $limit = 15): LengthAwarePaginator
        {
            return $this->repository->findByVenue($venueId, $limit);
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

    public function createSchemaDraft(Hall $hall, array $payload, int $userId, ?int $versionId = null, ?int $expectedVersion = null): HallSchemaVersion
    {
        return DB::transaction(function () use ($hall, $payload, $userId, $versionId, $expectedVersion) {
            // P2 (данные фона без лимита): сервер — единственная граница,
            // клиентский debounce её не заменяет. 2 MB JSON достаточно для
            // зала в несколько тысяч мест; dataURL-подложка в payload не
            // попадает (см. persistSchema на клиенте), а если кто-то пришлёт
            // её curl-ом — получим 422 вместо раздувания строки БД в каждом
            // автосейве.
            $this->assertPayloadSize($payload);

            // Server-side guard (A7): client-side validation is not a security
            // boundary — curl can post any payload. Reject invalid prices/canvas
            // before touching the draft row, with the §66 envelope (422).
            $this->validateSchemaPayload($payload);

            // B6: явная ссылка на версию из URL/payload больше не позволяет
            // притянуть чужую или уже опубликованную версию. Триггер БД
            // (trg_schema_version_immutable) заблокирует правку schema_json у
            // published/archived 500-й ошибкой SQLSTATE 45000, но клиенту мы
            // обязаны ответить осмысленным 409/404 в конверте §66, а не дать
            // «молчаливую мутацию» timestamp'а через no-op UPDATE.
            if ($versionId !== null) {
                $target = HallSchemaVersion::query()->find($versionId);
                if ($target === null || (int) $target->hall_id !== (int) $hall->id) {
                    throw new NotFoundError('HallSchemaVersion', (string) $versionId);
                }
                // P1 (неизменяемость обходима): доменное решение — тот же
                // canEditPayload, что покрывает REASON_FROZEN для
                // published/archived; триггер остаётся второй линией защиты.
                $editDecision = $this->schemaPolicy->canEditPayload($this->toDomain($target));
                if (!$editDecision->isAllowed()) {
                    $this->refuse($editDecision, 'SCHEMA_VERSION_NOT_DRAFT');
                }
                // P2 (silent last-write-wins): оптимистическая блокировка.
                // Клиент шлёт `base_updated_at` — updated_at версии на момент
                // последней его загрузки. Если строка тем временем изменила
                // другой редактор (или черновик пересоздан параллельной
                // вкладкой), молча затирать чужую работу нельзя — 409 с
                // актуальной меткой, клиент предложит перезагрузить черновик.
                if ($expectedVersion !== null) {
                    $current = (int) \Illuminate\Support\Carbon::parse($target->updated_at)->getTimestamp();
                    if ($current > $expectedVersion) {
                        throw new ConflictError(
                            'Черновик изменён другим редактором. Перезагрузите схему перед сохранением.',
                            'SCHEMA_DRAFT_CONFLICT',
                            ['version_id' => $target->id, 'current_updated_at' => $current],
                        );
                    }
                }
                $target->update(['schema_json' => $payload]);
                return $target->fresh();
            }

            // Check if there's already a draft version
            $existingDraft = $hall->schemaVersions()
                ->where('status', 'draft')
                ->orderByDesc('id')
                ->first();

            if ($existingDraft) {
                if ($expectedVersion !== null) {
                    $current = (int) \Illuminate\Support\Carbon::parse($existingDraft->updated_at)->getTimestamp();
                    if ($current > $expectedVersion) {
                        throw new ConflictError(
                            'Черновик изменён другим редактором. Перезагрузите схему перед сохранением.',
                            'SCHEMA_DRAFT_CONFLICT',
                            ['version_id' => $existingDraft->id, 'current_updated_at' => $current],
                        );
                    }
                }
                // Update existing draft
                $existingDraft->update([
                    'schema_json' => $payload,
                ]);
                return $existingDraft->fresh();
            }

            // Create new draft version
            return $this->repository->createSchemaVersion($hall, $payload, 'draft');
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
            // B5: findOrFail() бросает Laravel-исключение, которое рендерилось
            // как 500. Для несуществующей версии отвечаем 404 в конверте §66.
            $version = HallSchemaVersion::query()->find($versionId);

            if ($version === null) {
                throw new NotFoundError('HallSchemaVersion', (string) $versionId);
            }

            // P1 (publish чужой версии): версия обязана относиться к залу из
            // пути. Чужая версия для вызывающего неотличима от несуществующей.
            $hall = $this->repository->findByPublicId($hallPublicId);
            if ($hall === null || (int) $hall->id !== (int) $version->hall_id) {
                throw new NotFoundError('HallSchemaVersion', (string) $versionId);
            }

            // ПРАВИЛА ЖИЗНЕННОГО ЦИКЛА — единый источник истины: доменная
            // политика HallSchemas (P1 «мёртвый домен», P2 дуализм Policy).
            // Она проверяет: только draft публикуется (not_draft / frozen),
            // пустая схема не публикуется (REASON_EMPTY_PAYLOAD — раньше
            // «Нечего публиковать» жил только в UI, сервер публиковал
            // {sectors:[]}), и одна опубликованная версия на зал
            // (another_version_published).
            $siblings = HallSchemaVersion::query()
                ->where('hall_id', $version->hall_id)
                ->get()
                ->map(fn (HallSchemaVersion $v): DomainSchemaVersion => $this->toDomain($v))
                ->all();

            $decision = $this->schemaPolicy->canPublish($this->toDomain($version), $siblings);
            if (!$decision->isAllowed()) {
                $this->refuse($decision, 'SCHEMA_VERSION_NOT_DRAFT');
            }

            // Validate payload structure before publishing
            $this->validateSchemaPayload($version->schema_json ?? []);

            return $this->repository->publishSchemaVersion($version);
        });
    }

    public function archiveSchemaVersion(HallSchemaVersion $version): HallSchemaVersion
    {
        $decision = $this->schemaPolicy->canArchive($this->toDomain($version));
        if (!$decision->isAllowed()) {
            $this->refuse($decision, 'SCHEMA_VERSION_NOT_PUBLISHED');
        }

        $version->update(['status' => 'archived']);
        return $version;
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
        if (isset($payload['canvas']) && is_array($payload['canvas'])) {
            foreach (['width', 'height'] as $dim) {
                if (array_key_exists($dim, $payload['canvas'])) {
                    $v = $payload['canvas'][$dim];
                    if (!$this->isPositiveInt($v)) {
                        $errors["canvas.$dim"] = ["canvas.$dim must be a positive integer"];
                    }
                }
            }
        }

        // Принимаем любой объект с секторами (форматы редактора и импорта могут
        // отличаться: редактор — sectors[].seats, БД-формат — sectors[].rows[]).
        if (array_key_exists('sectors', $payload) && !is_array($payload['sectors'])) {
            $errors['sectors'] = ['sectors must be an array'];
        }

        foreach (($payload['sectors'] ?? []) as $i => $sector) {
            if (!is_array($sector)) {
                $errors["sectors.$i"] = ['sector must be an object'];
                continue;
            }

            if (!isset($sector['name']) || !is_string($sector['name']) || trim($sector['name']) === '') {
                $errors["sectors.$i.name"] = ['Each sector must have a non-empty name'];
            }

            // Редакторский формат: цена сектора в минорных единицах (§50).
            if (array_key_exists('priceMinor', $sector)) {
                $pm = $sector['priceMinor'];
                if ($pm !== null && !$this->isPositiveInt($pm)) {
                    $errors["sectors.$i.priceMinor"] = [
                        "Sector '{$sector['name']}' priceMinor must be an integer number of kopecks greater than zero",
                    ];
                }
            } elseif (array_key_exists('price', $sector)) {
                // Легаси-поле (сидеры/импорт): целые копейки, > 0.
                $p = $sector['price'];
                if ($p !== null && !$this->isPositiveInt($p)) {
                    $errors["sectors.$i.price"] = [
                        "Sector '{$sector['name']}' price must be an integer number of kopecks greater than zero",
                    ];
                }
            }

            // Цены по рядам (§50): rowPrices перекрывает цену сектора.
            if (isset($sector['rowPrices']) && is_array($sector['rowPrices'])) {
                foreach ($sector['rowPrices'] as $row => $rp) {
                    if ($rp !== null && !$this->isPositiveInt($rp)) {
                        $errors["sectors.$i.rowPrices.$row"] = [
                            "Row {$row} price must be an integer number of kopecks greater than zero",
                        ];
                    }
                }
            }

            // Места редакторского формата: цены не обязательны, но если есть — валидны.
            foreach (($sector['seats'] ?? []) as $j => $seat) {
                if (is_array($seat) && array_key_exists('priceMinor', $seat)
                    && $seat['priceMinor'] !== null && !$this->isPositiveInt($seat['priceMinor'])) {
                    $errors["sectors.$i.seats.$j.priceMinor"] = ['Seat priceMinor must be an integer greater than zero'];
                }
            }

            // БД-формат: sectors[].rows[].seats[] с price_amount (+ легаси price).
            foreach (($sector['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowNo = $row['number'] ?? '?';
                if (array_key_exists('price_amount', $row)
                    && $row['price_amount'] !== null && !$this->isPositiveInt($row['price_amount'])) {
                    $errors["sectors.$i.rows.$rowNo.price_amount"] = [
                        "Row {$rowNo} price_amount must be an integer number of kopecks greater than zero",
                    ];
                }
                foreach (($row['seats'] ?? []) as $seat) {
                    if (!is_array($seat)) {
                        continue;
                    }
                    foreach (['price_amount', 'price'] as $key) {
                        if (array_key_exists($key, $seat)
                            && $seat[$key] !== null && !$this->isPositiveInt($seat[$key])) {
                            $errors["sectors.$i.rows.$rowNo.seats.$key"] = [
                                "Seat {$key} must be an integer number of kopecks greater than zero",
                            ];
                        }
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationError($errors, 'Hall schema validation failed');
        }
    }

    /**
     * Strictly a positive whole number. Rejects floats (even 8500.0), strings,
     * bools, null, NaN-ish values — JSON numeric types arrive as int|float only.
     */
    private function isPositiveInt(mixed $v): bool
    {
        if (is_int($v)) {
            return $v >= 1;
        }
        if (is_float($v)) {
            return !is_nan($v) && !is_infinite($v) && floor($v) === $v && $v >= 1;
        }

        return false;
    }
}
