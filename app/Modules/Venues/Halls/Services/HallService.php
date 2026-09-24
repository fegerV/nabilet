<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Services;

use Nabilet\Modules\Venues\Halls\Models\Hall;
use Nabilet\Modules\Venues\Halls\Models\HallSchemaVersion;
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

    public function createSchemaDraft(Hall $hall, array $payload, int $userId): HallSchemaVersion
    {
        return DB::transaction(function () use ($hall, $payload, $userId) {
            // Check if there's already a draft version
            $existingDraft = $hall->schemaVersions()
                ->where('status', 'draft')
                ->first();

            if ($existingDraft) {
                // Update existing draft
                $existingDraft->update([
                    'payload' => $payload,
                    'updated_by' => $userId,
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
            $version = HallSchemaVersion::findOrFail($versionId);

            // Validate that we can publish this version
            $this->schemaPolicy->canPublish($version, $userId);

            // Validate payload structure before publishing
            $this->validateSchemaPayload($version->payload);

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

    protected function validateSchemaPayload(array $payload): void
    {
        // Validate required fields in schema payload
        if (!isset($payload['sectors']) || !is_array($payload['sectors'])) {
            throw new \InvalidArgumentException('Schema must contain sectors array');
        }

        foreach ($payload['sectors'] as $sector) {
            if (!isset($sector['name']) || !isset($sector['rows'])) {
                throw new \InvalidArgumentException('Each sector must have name and rows');
            }

            foreach ($sector['rows'] as $row) {
                if (!isset($row['label']) || !isset($row['seats'])) {
                    throw new \InvalidArgumentException('Each row must have label and seats');
                }
            }
        }
    }
}
