<?php

declare(strict_types=1);

namespace App\Modules\Core\Users\Services;

use App\Modules\Core\Users\Models\User;
use App\Modules\Core\Users\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        protected UserRepository $repository
    ) {}

    public function createUser(array $data): User
    {
        // Check if email already exists
        if ($this->repository->findByEmail($data['email'])) {
            throw new \RuntimeException('Email already registered');
        }

        return $this->repository->create($data);
    }

    public function findUser(int $userId): ?User
    {
        return $this->repository->find($userId);
    }

    public function updateUser(User $user, array $data): User
    {
        if (isset($data['email']) && $data['email'] !== $user->email) {
            if ($this->repository->findByEmail($data['email'])) {
                throw new \RuntimeException('Email already registered');
            }
        }

        return $this->repository->update($user, $data);
    }

    public function deleteUser(User $user): bool
    {
        return $this->repository->delete($user);
    }

    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->repository->findByEmail($email);

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    public function assignRoleToUser(User $user, int $roleId): void
    {
        $this->repository->assignRole($user, $roleId);
    }

    public function removeRoleFromUser(User $user, int $roleId): void
    {
        $this->repository->removeRole($user, $roleId);
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->repository->hasPermission($user, $permission);
    }

    public function searchUsers(string $query, int $limit = 15)
    {
        return $this->repository->search($query, $limit);
    }
}
