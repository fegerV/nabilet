<?php

declare(strict_types=1);

namespace App\Modules\Core\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Organizations\Services\OrganizationService;
use App\Modules\Core\Organizations\Http\Requests\StoreOrganizationRequest;
use App\Modules\Core\Organizations\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Core\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Core\Organizations\Http\Resources\OrganizationCollection;
use App\Modules\Core\Users\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $service,
        protected UserRepository $userRepository
    ) {}

    public function index(Request $request): OrganizationCollection
    {
        $limit = (int) $request->get('limit', 15);
        $organizations = $this->service->repository->all($limit);

        return new OrganizationCollection($organizations);
    }

    public function show(string $publicId): OrganizationResource
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        return new OrganizationResource($organization);
    }

    public function store(StoreOrganizationRequest $request): OrganizationResource
    {
        $organization = $this->service->createOrganization(
            $request->validated(),
            $request->user()
        );

        return new OrganizationResource($organization);
    }

    public function update(UpdateOrganizationRequest $request, string $publicId): OrganizationResource
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $organization = $this->service->updateOrganization($organization, $request->validated());

        return new OrganizationResource($organization);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $this->service->deleteOrganization($organization);

        return response()->json(['message' => 'Organization deleted successfully']);
    }

    public function addMember(Request $request, string $publicId): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', 'in:admin,member,cashier,manager'],
        ]);

        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $user = $this->userRepository->find($request->input('user_id'));
        
        if (!$user) {
            abort(404, 'User not found');
        }
        
        $role = $request->input('role', 'member');

        // Prevent adding owner as regular member
        if ($user->id === $organization->owner_id && $role !== 'admin') {
            return response()->json([
                'error' => 'Organization owner must have admin role',
            ], 422);
        }

        // Check if user is already a member
        $existingMembership = $organization->members()
            ->where('users.id', $user->id)
            ->first();

        if ($existingMembership) {
            // Update existing membership role
            $this->service->updateMemberRole($organization, $user, $role);
            
            return response()->json([
                'message' => 'Member role updated successfully',
                'data' => [
                    'user_id' => $user->id,
                    'role' => $role,
                ],
            ]);
        }

        $this->service->addMember($organization, $user, $role);

        return response()->json([
            'message' => 'Member added successfully',
            'data' => [
                'user_id' => $user->id,
                'role' => $role,
            ],
        ], 201);
    }

    public function removeMember(string $publicId, int $userId): JsonResponse
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $user = $this->userRepository->find($userId);

        if (!$user) {
            abort(404, 'User not found');
        }

        // Prevent removing the owner
        if ($user->id === $organization->owner_id) {
            return response()->json([
                'error' => 'Cannot remove organization owner',
            ], 422);
        }

        // Check if user is a member
        $isMember = $organization->members()
            ->where('users.id', $userId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'error' => 'User is not a member of this organization',
            ], 404);
        }

        $this->service->removeMember($organization, $user);

        return response()->json([
            'message' => 'Member removed successfully',
            'data' => [
                'user_id' => $userId,
            ],
        ]);
    }
}
