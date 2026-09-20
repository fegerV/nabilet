<?php

declare(strict_types=1);

namespace App\Modules\Core\Organizations\Services;

use App\Modules\Core\Organizations\Models\Organization;
use App\Modules\Core\Organizations\Repositories\OrganizationRepository;
use App\Modules\Core\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationService
{
    public function __construct(
        protected OrganizationRepository $repository
    ) {}

    public function createOrganization(array $data, User $owner): Organization
    {
        return DB::transaction(function () use ($data, $owner) {
            $slug = $this->generateUniqueSlug($data['name'] ?? 'organization');
            
            $organization = $this->repository->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'public_id' => Str::uuid()->toString(),
                'slug' => $slug,
                'owner_id' => $owner->id,
                'status' => 'active',
            ]);

            // Assign owner as admin member
            $organization->members()->attach($owner->id, ['role' => 'admin']);

            return $organization->load(['owner', 'members']);
        });
    }

    public function updateOrganization(Organization $organization, array $data): Organization
    {
        if (isset($data['name']) && $data['name'] !== $organization->name) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], $organization->id);
        }

        return $this->repository->update($organization, $data);
    }

    public function deleteOrganization(Organization $organization): bool
    {
        return DB::transaction(function () use ($organization) {
            // Detach all members
            $organization->members()->detach();
            
            // Soft delete or hard delete based on policy
            return $this->repository->delete($organization);
        });
    }

    public function addMember(Organization $organization, User $user, string $role = 'member'): void
    {
        $organization->members()->attach($user->id, ['role' => $role]);
    }

    public function removeMember(Organization $organization, User $user): void
    {
        $organization->members()->detach($user->id);
    }

    public function updateMemberRole(Organization $organization, User $user, string $role): void
    {
        $organization->members()->updateExistingPivot($user->id, ['role' => $role]);
    }

    protected function generateUniqueSlug(string $name, int $excludeId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while ($this->repository->findBySlug($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $counter++;
        }

        return $slug;
    }
}
