<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Repositories;

use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class HallRepository
{
    public function __construct(
        protected Hall $model
    ) {}

    /**
     * Зал с геометрией опубликованной схемы.
     *
     * Цепочка была `currentSchemaVersion.sectors.rows.seats`, и это не работало
     * даже после появления `currentSchemaVersion`: у `Sector` отношение
     * называется `hallRows()`, а не `rows()` (см. `Sector`), а у `HallRow` —
     * `seats()`. Laravel падает на первом неизвестном отношении, поэтому вызов
     * давал 500, а не пустой результат.
     *
     * Метод сейчас не вызывается ниоткуда; цепочка исправлена, чтобы он не
     * оставался миной. Витрина берёт геометрию из `GET /halls/{publicId}/schema`
     * (только опубликованная версия), а не отсюда.
     */
    public function find(int $id): ?Hall
    {
        return $this->model->with(['venue', 'currentSchemaVersion.sectors.hallRows.seats'])->find($id);
    }

    public function findByPublicId(string $publicId): ?Hall
    {
        return $this->model->where('public_id', $publicId)->first();
    }

    /**
     * Returns a paginator, not a plain Collection: the body calls paginate(), and
     * the declared Eloquent Collection type made every call a TypeError. The type
     * is corrected rather than the call, because HallCollection is a
     * ResourceCollection and the controller feeds it straight through — the
     * paginated envelope is what the caller expects.
     */
    public function findByVenue(int $venueId, int $limit = 15): LengthAwarePaginator
        {
            // Без `with(['currentSchemaVersion'])`. Жадная загрузка здесь была
            // причиной 500: отношения не существовало, и Laravel падал на нём
            // («Call to undefined relationship [currentSchemaVersion] on model
            // [Hall]»), поэтому список залов площадки не открывался. Отношение
            // теперь определено (`Hall::currentSchemaVersion()`), но грузить его
            // по-прежнему незачем: ответ формирует `HallResource`, а он отдаёт
            // только id, public_id, name, venue_id — ни `current_schema_version`,
            // ни геометрии в списке нет. Проверено по ключам ответа.
            return $this->model->where('venue_id', $venueId)
                ->paginate($limit);
        }

        /**
             * Пагинированный список всех залов.
             */
            public function paginate(int $limit = 100): LengthAwarePaginator
            {
                return $this->model->with(['venue'])->paginate($limit);
            }

    public function create(array $data): Hall
    {
        return $this->model->create($data);
    }

    public function update(Hall $hall, array $data): Hall
    {
        $hall->update($data);
        return $hall->fresh();
    }

    public function delete(Hall $hall): bool
    {
        return $hall->delete();
    }

    public function getSchemaVersions(Hall $hall): Collection
    {
        return $hall->schemaVersions()->orderBy('version', 'desc')->get();
    }

    public function createSchemaVersion(Hall $hall, array $payload, string $status = 'draft'): HallSchemaVersion
        {
            $latestVersion = $hall->schemaVersions()->max('version') ?? 0;

            return $hall->schemaVersions()->create([
                'version' => $latestVersion + 1,
                'revision' => 1,
                'schema_json' => $payload,
                'status' => $status,
            ]);
        }

    public function publishSchemaVersion(HallSchemaVersion $version): HallSchemaVersion
    {
        // HallService has already applied the domain transition under the hall
        // lock; the repository must not silently bypass that policy by archiving
        // sibling versions as a side effect.
        $version->update(['status' => 'published', 'published_at' => now()]);

        return $version->fresh();
    }
}
