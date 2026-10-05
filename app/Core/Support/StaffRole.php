<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Единая точка ответа на вопрос «это сотрудник?».
 *
 * Одна и та же проверка нужна в трёх местах, и до этого класса она была
 * скопирована в middleware (`EnsureAdminRole`) и отсутствовала в контроллерах
 * заказов/платежей/билетов — из-за чего те отдавали данные любого клиента
 * анонимному запросу (IDOR/BOLA).
 *
 * Роли живут в `user_roles` (belongsToMany через `User::roles()`), а не в
 * `users.organization_id`: такой колонки в схеме нет, членство в организации
 * описано таблицей `user_organization`. Поэтому «сотрудник» — это набор ролей,
 * а не поле пользователя.
 *
 * ВАЖНО: это проверка КЛАССА доступа, а не владельца ресурса. Сотрудник имеет
 * право видеть чужие заказы; обычный пользователь — нет, и это проверяется
 * отдельно, по `user_id` ресурса (см. контроллеры). Заменять одно другим нельзя.
 */
final class StaffRole
{
    /** Роли с доступом к админ-API. */
    public const ROLES = ['admin', 'manager'];

    /**
     * @param object|null $user пользователь из `$request->user()`
     */
    public static function isStaff(?object $user): bool
    {
        if ($user === null || ! method_exists($user, 'roles')) {
            return false;
        }

        return $user->roles->pluck('slug')->intersect(self::ROLES)->isNotEmpty();
    }
}
