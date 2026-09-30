<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Services;

use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Venues\Halls\Repositories\HallRepository;
use Nabilet\Modules\Venues\Halls\Domain\SchemaVersionPolicy;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HallService
{
    public function __construct(
        protected HallRepository $repository,
        protected SchemaVersionPolicy $schemaPolicy
    ) {}

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

    public function createSchemaDraft(Hall $hall, array $payload, int $userId, ?int $versionId = null): HallSchemaVersion
    {
        return DB::transaction(function () use ($hall, $payload, $userId, $versionId) {
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
                if ($target->status !== 'draft') {
                    throw new ConflictError(
                        "Schema version {$target->version} is '{$target->status}' and immutable; create a new draft instead.",
                        'SCHEMA_VERSION_IMMUTABLE',
                        ['version_id' => $target->id, 'status' => $target->status],
                    );
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

    public function publishSchemaVersion(int $versionId, int $userId): HallSchemaVersion
    {
        return DB::transaction(function () use ($versionId, $userId) {
            // B5: findOrFail() бросает Laravel-исключение, которое рендерилось
            // как 500. Для несуществующей версии отвечаем 404 в конверте §66.
            $version = HallSchemaVersion::query()->find($versionId);

            if ($version === null) {
                throw new NotFoundError('HallSchemaVersion', (string) $versionId);
            }

            // B5/B6: повторная публикация уже опубликованной версии раньше
            // возвращала 200 и молча обновляла published_at/timestamp (за счёт
            // no-op UPDATE, который триггер неизменяемости пропускает). Теперь
            // это явный конфликт 409 — мутации нет.
            if ($version->status === 'published') {
                throw new ConflictError(
                    "Schema version {$version->version} is already published.",
                    'SCHEMA_VERSION_ALREADY_PUBLISHED',
                    ['version_id' => $version->id],
                );
            }
            if ($version->status !== 'draft') {
                throw new ConflictError(
                    "Only draft schema versions can be published (current status: {$version->status}).",
                    'SCHEMA_VERSION_NOT_DRAFT',
                    ['version_id' => $version->id, 'status' => $version->status],
                );
            }

            // Validate payload structure before publishing
            $this->validateSchemaPayload($version->schema_json ?? []);

            return $this->repository->publishSchemaVersion($version);
        });
    }

    public function archiveSchemaVersion(HallSchemaVersion $version): HallSchemaVersion
    {
        if ($version->status === 'published') {
            throw new \RuntimeException('Cannot archive a published schema version');
        }

        $version->update(['status' => 'archived']);
        return $version;
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
