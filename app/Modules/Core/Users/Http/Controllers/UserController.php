<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Services\UserService;

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
        $user = $this->service->findUserByPublicId($publicId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json(['data' => $user]);
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

        try {
            $user = $this->service->createUser($validated);
            return response()->json(['data' => $user, 'message' => 'User created successfully'], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $user = $this->service->findUserByPublicId($publicId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|min:8',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'status' => 'sometimes|in:active,inactive,banned',
        ]);

        try {
            $updatedUser = $this->service->updateUser($user, $validated);
            return response()->json(['data' => $updatedUser, 'message' => 'User updated successfully']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(string $publicId): JsonResponse
    {
        $user = $this->service->findUserByPublicId($publicId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $this->service->deleteUser($user);

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function assignRole(Request $request, string $publicId): JsonResponse
    {
        $user = $this->service->findUserByPublicId($publicId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'role_id' => 'required|integer|exists:roles,id',
        ]);

        $this->service->assignRoleToUser($user, $validated['role_id']);

        return response()->json(['message' => 'Role assigned successfully']);
    }

    public function removeRole(string $publicId, int $roleId): JsonResponse
    {
        $user = $this->service->findUserByPublicId($publicId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $this->service->removeRoleFromUser($user, $roleId);

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
}
