<?php

declare(strict_types=1);

namespace App\Modules\Venues\Halls\Repositories;

use App\Modules\Venues\Halls\Models\Hall;
use App\Modules\Venues\Halls\Models\HallSchemaVersion;
use Illuminate\Database\Eloquent\Collection;

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

    public function findByVenue(int $venueId, int $limit = 15): Collection
    {
        return $this->model->where('venue_id', $venueId)
            ->with(['currentSchemaVersion'])
            ->paginate($limit);
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
            'payload' => $payload,
            'status' => $status,
            'created_by' => auth()->id(),
        ]);
    }

    public function publishSchemaVersion(HallSchemaVersion $version): HallSchemaVersion
    {
        // Unpublish any currently published version for this hall
        HallSchemaVersion::where('hall_id', $version->hall_id)
            ->where('status', 'published')
            ->update(['status' => 'archived']);

        $version->update(['status' => 'published']);
        
        // Update hall's current_schema_version_id
        $version->hall->update(['current_schema_version_id' => $version->id]);

        return $version->fresh();
    }
}
