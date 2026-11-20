<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Единственная точка ответа на вопрос «это администратор?».
 *
 * РАНЬШЕ здесь было ROLES = ['admin', 'manager'] и проверка шла по
 * `$user->roles` — связке `user_roles` БЕЗ фильтра по организации. Из-за этого:
 *  1) manager проходил middleware `admin` и мог назначать себе роль admin;
 *  2) admin в организации A становился «сотрудником» организации B, где он
 *     был обычным member, потому что роль глобальна, а контекст — первый
 *     membership по id.
 *
 * Теперь роль ОДНА (`admin`) и проверяется ТОЛЬКО в рамках текущей организации.
 * Класс не кеширует результат: один и тот же пользователь в разных запросах
 * может работать в разных арендаторах.
 */
final class StaffRole
{
    /** Единственная роль с доступом к админ-API. */
    public const ADMIN = 'admin';

    /** @deprecated оставлено для совместимости вызовов; используйте ADMIN */
    public const ROLES = [self::ADMIN];

    /**
     * Администратор текущей организации?
     *
     * @param object|null  $user  пользователь из `$request->user()`
     * @param int|null  $organizationId  явный контекст; null → текущий из OrganizationContext
     */
    public static function isStaff(?object $user, ?int $organizationId = null): bool
    {
        if ($user === null) {
            return false;
        }
        if (! isset($user->id)) {
            // Несохранённый пользователь из юнит-теста: только in-memory fallback.
            if (method_exists($user, 'relationLoaded') && $user->relationLoaded('roles')) {
                return $user->roles->pluck('slug')->contains(self::ADMIN);
            }

            return false;
        }

        $organizationId ??= self::currentOrganizationId($user);
        if ($organizationId === null || $organizationId <= 0) {
            // Юнит-тесты FormRequest'ов работают с пользователем, у которого
            // relation `roles` уже загружена, но HTTP-контекста нет. В реальном
            // запросе контекст всегда выставлен middleware, поэтому этот путь
            // используется только вне HTTP. Роль — строго admin.
            if (method_exists($user, 'relationLoaded') && $user->relationLoaded('roles')) {
                return $user->roles->pluck('slug')->contains(self::ADMIN);
            }

            return false;
        }

        $adminRoleId = DB::table('roles')->where('slug', self::ADMIN)->value('id');
        if ($adminRoleId === null) {
            return false;
        }

        // Основной источник — user_roles с organization_id (именно туда пишет
        // установщик и UserService::assignRole).
        if (DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('role_id', $adminRoleId)
            ->exists()) {
            return true;
        }

        // Совместимость со старыми фикстурами/настройками, где роль хранилась
        // только в user_organization.role_id.
        return DB::table('user_organization')
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('role_id', $adminRoleId)
            ->exists();
    }

    private static function currentOrganizationId(?object $user): ?int
    {
        try {
            $id = app(\Nabilet\Core\Tenancy\OrganizationContext::class)->idOrNull();
            if ($id !== null) {
                return (int) $id;
            }
        } catch (\Throwable) {
            // Ранний bootstrap или консоль — контекст ещё не создан.
        }

        $id = UserOrganization::id($user);

        return $id === null ? null : (int) $id;
    }
}
