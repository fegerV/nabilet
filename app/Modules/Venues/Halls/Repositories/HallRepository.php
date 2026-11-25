<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Repositories;

use Nabilet\Modules\HallSchemas\Domain\SchemaVersionState;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Modules\Venues\Models\Venue;
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

    /**
     * Запись идёт только в реальную таблицу venues (2026_11_18_000800):
     * `halls` — VIEW, INSERT в неё запрещён, а `name` вычисляется через
     * COALESCE и не обновляется. Возвращаем совместимую in-memory Hall.
     * Специфические поля зала живут в halls_name/capacity/width/height/photo
     * колонках площадки (миграция 2026_11_26_000100).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Hall
    {
        $venue = Venue::query()->create([
            'public_id' => $data['public_id'] ?? (string) \Nabilet\Modules\Core\Support\PublicId::generate(),
            'organization_id' => $data['organization_id'] ?? app(\Nabilet\Core\Tenancy\OrganizationContext::class)->id(),
            'name' => $data['name'],
            'hall_name' => $data['name'],
            'slug' => \Illuminate\Support\Str::slug($data['name']) . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
            'description' => $data['description'] ?? null,
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'exterior_photo_url' => $data['exterior_photo_url'] ?? null,
            'interior_photo_url' => $data['interior_photo_url'] ?? null,
            'status' => $data['status'] ?? \Nabilet\Core\Support\PublicationStatus::ACTIVE,
        ]);

        return $this->shadowHall($venue);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Hall $hall, array $data): Hall
    {
        $venue = Venue::query()->findOrFail($hall->venue_id ?: $hall->id);
        $venue->update($this->toVenueAttributes($data));

        return $this->shadowHall($venue->fresh());
    }

    public function delete(Hall $hall): bool
    {
        // VIEW удаляет строку площадки, но обходит сервисный запрет на
        // замороженные схемы. Удаляем из таблицы — БД-триггер ещё один барьер.
        return (bool) Venue::query()->whereKey($hall->venue_id ?: $hall->id)->delete();
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function toVenueAttributes(array $data): array
    {
        $map = [
            'name' => 'hall_name',
            'description' => 'description',
            'city' => 'city',
            'address' => 'address',
            'capacity' => 'capacity',
            'width' => 'width',
            'height' => 'height',
            'exterior_photo_url' => 'exterior_photo_url',
            'interior_photo_url' => 'interior_photo_url',
            'status' => 'status',
        ];
        $attributes = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data)) {
                $attributes[$to] = $data[$from];
            }
        }

        return $attributes;
    }

    private function shadowHall(Venue $venue): Hall
    {
        return (new Hall())->forceFill([
            'id' => $venue->id,
            'public_id' => $venue->public_id,
            'venue_id' => $venue->id,
            'name' => $venue->hall_name ?? $venue->name,
            'description' => $venue->description,
            'city' => $venue->city,
            'address' => $venue->address,
            'capacity' => $venue->capacity,
            'width' => $venue->width,
            'height' => $venue->height,
            'exterior_photo_url' => $venue->exterior_photo_url,
            'interior_photo_url' => $venue->interior_photo_url,
            'organization_id' => $venue->organization_id,
            'status' => $venue->status,
        ]);
    }

    public function getSchemaVersions(Hall $hall): Collection
    {
        return $hall->schemaVersions()->orderBy('version', 'desc')->get();
    }

    public function createSchemaVersion(Hall $hall, array $payload, string $status = SchemaVersionState::DRAFT): HallSchemaVersion
    {
        $latestVersion = $hall->schemaVersions()->max('version') ?? 0;

        return $hall->schemaVersions()->create([
            'venue_id' => $hall->venue_id,
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
        $version->update(['status' => SchemaVersionState::PUBLISHED, 'published_at' => now()]);

        return $version->fresh();
    }
}
