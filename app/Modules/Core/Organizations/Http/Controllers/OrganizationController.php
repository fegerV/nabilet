<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Organizations\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Organizations\Services\OrganizationService;
use Nabilet\Modules\Core\Organizations\Http\Requests\StoreOrganizationRequest;
use Nabilet\Modules\Core\Organizations\Http\Requests\UpdateOrganizationRequest;
use Nabilet\Modules\Core\Organizations\Http\Resources\OrganizationResource;
use Nabilet\Modules\Core\Organizations\Http\Resources\OrganizationCollection;
use Nabilet\Modules\Core\Users\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization endpoints.
 *
 * Two failure shapes were removed from this controller, both of which produced
 * non-conforming bodies:
 *
 *  - `abort(404, 'Organization not found')` → Laravel's default renderer answers
 *    `{"message":"Organization not found","exception":"NotFoundHttpException",…}`,
 *    which is not the §66 envelope and, with `APP_DEBUG=true`, included a stack trace.
 *    Replaced by `NotFoundError`, which also carries a stable `ORGANIZATION_NOT_FOUND`.
 *
 *  - `response()->json(['error' => '<string>'])` → `error` must be an *object* with
 *    `code` and `message`; a flat string gives the client nothing to branch on and
 *    drops `request_id`. Replaced by `DomainRuleViolation` / `NotFoundError`.
 */
class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $service,
        protected UserRepository $userRepository
    ) {}

    public function index(Request $request): OrganizationCollection
    {
        $limit = (int) $request->get('limit', 15);

        return new OrganizationCollection($this->service->all($limit));
    }

    public function show(string $publicId): OrganizationResource
    {
        return new OrganizationResource($this->findOrFail($publicId));
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
        $organization = $this->service->updateOrganization(
            $this->findOrFail($publicId),
            $request->validated()
        );

        return new OrganizationResource($organization);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $this->service->deleteOrganization($this->findOrFail($publicId));

        return response()->json(['message' => 'Organization deleted successfully']);
    }

    public function addMember(Request $request, string $publicId): JsonResponse
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast: `users.id` is BIGINT, so a
            // non-numeric `user_id` would raise SQLSTATE[22P02] and answer 500 instead
            // of 422. See `CartController::addItem()` for the full explanation.
            'user_id' => ['bail', 'required', 'integer', 'exists:users,id'],
            'role' => ['required', 'in:admin,member,cashier,manager'],
        ]);

        $organization = $this->findOrFail($publicId);

        $user = $this->userRepository->find((int) $validated['user_id']);

        if (!$user) {
            throw new NotFoundError('User', (int) $validated['user_id']);
        }

        $role = $validated['role'];

        // The owner is an admin by construction (`createOrganization()` attaches them
        // with role `admin`). Demoting them would leave the organization with no one
        // able to administer it, so the attempt is refused rather than silently ignored.
        if ($user->id === $organization->owner_id && $role !== 'admin') {
            throw new DomainRuleViolation(
                'The organization owner must keep the admin role.',
                'ORGANIZATION_OWNER_REQUIRES_ADMIN_ROLE',
            );
        }

        // Check if user is already a member
        $existingMembership = $organization->members()
            ->where('users.id', $user->id)
            ->first();

        if ($existingMembership) {
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
        $organization = $this->findOrFail($publicId);

        $user = $this->userRepository->find($userId);

        if (!$user) {
            throw new NotFoundError('User', $userId);
        }

        // An organization must always retain its owner as a member; removing them
        // would orphan it (see `addMember()` for the matching role guard).
        if ($user->id === $organization->owner_id) {
            throw new DomainRuleViolation(
                'The organization owner cannot be removed.',
                'ORGANIZATION_OWNER_IMMUTABLE',
            );
        }

        // Check if user is a member
        $isMember = $organization->members()
            ->where('users.id', $userId)
            ->exists();

        // 404 rather than 403: "you are not a member" and "that membership does not
        // exist" must be indistinguishable, or the response enumerates memberships.
        if (!$isMember) {
            throw new NotFoundError('Membership', $userId);
        }

        $this->service->removeMember($organization, $user);

        return response()->json([
            'message' => 'Member removed successfully',
            'data' => [
                'user_id' => $userId,
            ],
        ]);
    }

    /**
     * Resolve an organization by its public id or fail with the envelope's 404.
     *
     * Centralised so the five read/write paths cannot drift into five different
     * messages — and so the public id is echoed back in `details`, which is what makes
     * a support ticket actionable.
     */
    private function findOrFail(string $publicId): Organization
    {
        $organization = $this->service->findByPublicId($publicId);

        if (!$organization) {
            throw new NotFoundError('Organization', $publicId);
        }

        return $organization;
    }
}
