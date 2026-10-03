<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Organizations\Repositories;

use Nabilet\Modules\Core\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

class OrganizationRepository
{
    public function __construct(
        protected Organization $model
    ) {}

    public function find(int $id): ?Organization
    {
        return $this->model->with(['owner', 'members'])->find($id);
    }

    public function findByPublicId(string $publicId): ?Organization
    {
        return $this->model->where('public_id', $publicId)->first();
    }

    public function findBySlug(string $slug, int $organizationId = null): ?Organization
    {
        $query = $this->model->where('slug', $slug);
        
        if ($organizationId) {
            $query->where('id', '!=', $organizationId);
        }
        
        return $query->first();
    }

    public function all(int $limit = 15): Collection
    {
        return $this->model->with(['owner'])->paginate($limit);
    }

    public function create(array $data): Organization
    {
        return $this->model->create($data);
    }

    public function update(Organization $organization, array $data): Organization
    {
        $organization->update($data);
        return $organization->fresh();
    }

    public function delete(Organization $organization): bool
    {
        return $organization->delete();
    }

    public function getMemberCount(Organization $organization): int
    {
        return $organization->members()->count();
    }
}
