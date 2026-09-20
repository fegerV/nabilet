<?php

declare(strict_types=1);

namespace App\Modules\Core\Users\Repositories;

use App\Modules\Core\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function __construct(
        protected User $model
    ) {}

    public function find(int $id): ?User
    {
        return $this->model->with(['roles', 'organizations'])->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function findByPublicId(string $publicId): ?User
    {
        return $this->model->where('public_id', $publicId)->first();
    }

    public function all(int $limit = 15): Collection
    {
        return $this->model->with(['roles'])->paginate($limit);
    }

    public function create(array $data): User
    {
        return $this->model->create([
            'email' => $data['email'],
            'name' => $data['name'],
            'password' => bcrypt($data['password']),
            'public_id' => \Str::uuid()->toString(),
            'email_verified_at' => now(),
        ]);
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        $user->update($data);
        return $user->fresh();
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function assignRole(User $user, int $roleId): void
    {
        $user->roles()->attach($roleId);
    }

    public function removeRole(User $user, int $roleId): void
    {
        $user->roles()->detach($roleId);
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $user->roles()->whereHas('permissions', function ($query) use ($permission) {
            $query->where('name', $permission);
        })->exists();
    }

    public function search(string $query, int $limit = 15): Collection
    {
        return $this->model->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->with(['roles'])
            ->paginate($limit);
    }
}
