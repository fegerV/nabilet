<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Services\UserService;

/**
 * User administration endpoints.
 *
 * Every failure here used to be `['message' => …]`, which is not the §66 envelope —
 * the contract requires a top-level `error` object, so a body with only `message` has
 * no `code` for the client to branch on and no `request_id`. Those are now
 * `NotFoundError` / `DomainRuleViolation`, rendered by `ApiExceptionRenderer`.
 */
class UserController
{
    public function __construct(
        protected UserService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 15);
        $users = $this->service->searchUsers('', $limit);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->findOrFail($publicId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = $this->service->createUser($validated);

        return response()->json(['data' => $user, 'message' => 'User created successfully'], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $user = $this->findOrFail($publicId);

        $validated = $request->validate([
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|min:8',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'status' => 'sometimes|in:active,inactive,banned',
        ]);

        $updatedUser = $this->service->updateUser($user, $validated);

        return response()->json(['data' => $updatedUser, 'message' => 'User updated successfully']);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $this->service->deleteUser($this->findOrFail($publicId));

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function assignRole(Request $request, string $publicId): JsonResponse
    {
        $user = $this->findOrFail($publicId);

        $validated = $request->validate([
            // `bail` is required in addition to `integer`: `roles.id` is BIGINT, and
            // Laravel only stops evaluating an attribute's remaining rules when `bail`
            // is present — so without it `exists` still runs and PostgreSQL raises
            // SQLSTATE[22P02], turning a 422 into a 500.
            // See `CartController::addItem()` for the full explanation.
            'role_id' => 'bail|required|integer|exists:roles,id',
        ]);

        $this->service->assignRoleToUser($user, (int) $validated['role_id']);

        return response()->json(['message' => 'Role assigned successfully']);
    }

    public function removeRole(string $publicId, int $roleId): JsonResponse
    {
        $this->service->removeRoleFromUser($this->findOrFail($publicId), $roleId);

        return response()->json(['message' => 'Role removed successfully']);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');
        $limit = (int) $request->get('limit', 15);

        $users = $this->service->searchUsers($query, $limit);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * @throws NotFoundError
     */
    private function findOrFail(string $publicId): User
    {
        $user = $this->service->findUserByPublicId($publicId);

        if (!$user) {
            throw new NotFoundError('User', $publicId);
        }

        return $user;
    }
}
