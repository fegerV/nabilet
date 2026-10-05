<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Repositories;

use Nabilet\Modules\Core\Users\Models\User;
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

    /**
     * `public_id` is NOT set here: the `User` model generates it in `booted()`.
     *
     * It used to be `Str::uuid()->toString()` — 36 characters into a `CHAR(26)`
     * column, which MySQL refused with 1406 and turned every registration into a
     * 422. The model owns the value now so that every creation path agrees.
     *
     * There is no `name` key either: `users` has no such column (the schema keeps
     * `first_name`/`last_name`), and it was not in `$fillable`, so the line was
     * silently discarded by mass assignment.
     */
    public function create(array $data): User
    {
        return $this->model->create([
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'email_verified_at' => now(),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
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
        return $user->roles()->whereHas('permissions', function ($q) use ($permission) {
            $q->where('name', $permission);
        })->exists();
    }

    public function search(string $query, int $limit = 15): Collection
    {
        return $this->model->where(function ($q) use ($query) {
                $q->where('first_name', 'like', "%{$query}%")
                  ->orWhere('last_name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->with(['roles'])
            ->paginate($limit);
    }
}
