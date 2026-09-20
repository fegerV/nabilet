<?php

declare(strict_types=1);

namespace App\Modules\Venues\Halls\Services;

use App\Modules\Venues\Halls\Models\Hall;
use App\Modules\Venues\Halls\Models\HallSchemaVersion;
use App\Modules\Venues\Halls\Repositories\HallRepository;
use App\Modules\Venues\Halls\Domain\SchemaVersionPolicy;
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
