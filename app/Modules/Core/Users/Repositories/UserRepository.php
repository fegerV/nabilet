<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\LikePattern;
use Nabilet\Modules\Core\Users\Models\User;

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

    /**
     * Тип возврата — пагинатор, а не Collection: `paginate()` отдаёт
     * LengthAwarePaginator, и объявленный `Collection` ронял GET /users
     * с TypeError 500 (админка оставалась без списка пользователей).
     * Контроллер берёт `$users->items()` и meta — это и есть контракт.
     */
    public function all(int $limit = 15): LengthAwarePaginator
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
        // Последний рубеж перед записью: пустое значение означает «пароль не
        // меняется», а не «поставить пустой». Без этой проверки
        // `$user->update(['password' => null])` записывал в NOT NULL колонку
        // пустую строку (MySQL приводит NULL к '' в нестрогом режиме), и
        // администратор, отправивший форму без нового пароля, стирал пароль
        // пользователя. См. `PasswordRules::optional()` и `UserService::updateUser()`.
        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

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

    /**
     * Выдать пользователю роль в организации.
     *
     * Раньше это было `$user->roles()->attach($roleId)`, то есть INSERT только
     * `(user_id, role_id)`. Но `user_roles.organization_id` — NOT NULL, поэтому
     * на реальной схеме эндпоинт отвечал 500 «Field 'organization_id' doesn't
     * have a default value»: назначить роль через API было невозможно.
     *
     * Пишем в ОБЕ таблицы членства, как это делают установщик
     * (`InstallerController::bootstrapOrganization`) и сидер
     * (`NabiletAdminSeeder`): `user_roles` — источник для `StaffRole::isStaff()`,
     * `user_organization` — каноничная таблица членства, из которой
     * `User::organizations()` достаёт организацию.
     *
     * `insertOrIgnore` вместо `insert`: у `user_roles` есть UNIQUE
     * (user_id, organization_id, role_id), и повторная выдача той же роли —
     * идемпотентная операция, а не 500.
     */
    public function assignRole(User $user, int $roleId, int $organizationId): void
    {
        $now = now();

        DB::table('user_roles')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);
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

    public function search(string $query, int $limit = 15): LengthAwarePaginator
    {
        // Экранирование обязательно, и раньше его здесь НЕ БЫЛО: поиск
        // подставлял ввод прямо в шаблон (`"%{$query}%"`), поэтому `_` совпадал с
        // любым символом, а `%` — с любым количеством символов. Это не инъекция
        // (значения биндятся), но пользователь управлял формой запроса и мог
        // заставить MySQL читать всю таблицу. См. `LikePattern`.
        $pattern = LikePattern::contains($query);

        return $this->model->where(function ($q) use ($pattern) {
                $q->whereRaw(LikePattern::expression('first_name'), [$pattern])
                  ->orWhereRaw(LikePattern::expression('last_name'), [$pattern])
                  ->orWhereRaw(LikePattern::expression('email'), [$pattern]);
            })
            ->with(['roles'])
            ->paginate($limit);
    }
}
