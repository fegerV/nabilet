<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Repositories;

use Nabilet\Modules\Venues\Halls\Models\Hall;
use Nabilet\Modules\Venues\Halls\Models\HallSchemaVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class HallRepository
{
    public function __construct(
        protected Hall $model
    ) {}

    public function find(int $id): ?Hall
    {
        return $this->model->with(['venue', 'currentSchemaVersion.sectors.rows.seats'])->find($id);
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
            return $this->model->where('venue_id', $venueId)
                ->with(['currentSchemaVersion'])
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
                'schema_json' => $payload,
                'status' => $status,
            ]);
        }

    public function publishSchemaVersion(HallSchemaVersion $version): HallSchemaVersion
        {
            // Unpublish any currently published version for this hall
            HallSchemaVersion::where('hall_id', $version->hall_id)
                ->where('status', 'published')
                ->where('id', '!=', $version->id)
                ->update(['status' => 'archived']);

            $version->update(['status' => 'published', 'published_at' => now()]);

            return $version->fresh();
    }
}
