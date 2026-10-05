<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Демо-данные для админки: роли, права, организация, три пользователя.
 *
 * Сидер идемпотентен — его безопасно запускать повторно (`db:seed --force`)
 * на живой базе. Раньше он был «одноразовым»: повторный запуск падал на
 * `uq_users_email` / `uq_roles_slug`, а на шаред-хостинге это единственный
 * способ поднять панель.
 *
 * Ключевое исправление: сидер писал членство только в `user_roles`, но не в
 * `user_organization` — каноничную таблицу членства (ТЗ §«Identity»,
 * `nabilet_core_spec/migrations/001_identity.sql`). Из-за этого
 * `User::organizations()` (belongsToMany поверх `user_organization`) всегда
 * возвращал пусто, и админ не мог создать ни площадку, ни зал, ни событие:
 * `VenueController::store()` / `EventController::store()` берут из этой связи
 * `organization_id`. Пишем обе таблицы.
 */
class NabiletAdminSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin' => ['Администратор', 'Полный доступ к системе'],
            'manager' => ['Менеджер', 'Управление мероприятиями и заказами'],
            'support' => ['Саппорт', 'Просмотр данных, управление билетами'],
        ];

        $roleIds = [];
        foreach ($roles as $slug => [$name, $description]) {
            $existing = DB::table('roles')->where('slug', $slug)->value('id');
            $roleIds[$slug] = $existing !== null
                ? (int) $existing
                : (int) DB::table('roles')->insertGetId([
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                ]);
        }

        $adminRoleId = $roleIds['admin'];
        $managerRoleId = $roleIds['manager'];
        $supportRoleId = $roleIds['support'];

        // permissions: id, name, slug (таблица без timestamps)
        $perms = [
            ['view_events', 'Просмотр мероприятий'],
            ['create_events', 'Создание мероприятий'],
            ['edit_events', 'Редактирование мероприятий'],
            ['delete_events', 'Удаление мероприятий'],
            ['view_orders', 'Просмотр заказов'],
            ['edit_orders', 'Редактирование заказов'],
            ['view_payments', 'Просмотр платежей'],
            ['refund_payments', 'Возврат платежей'],
            ['view_tickets', 'Просмотр билетов'],
            ['checkin_tickets', 'Чекин билетов'],
            ['force_checkin', 'Принудительный чекин'],
            ['override_inventory', 'Переопределение инвентаря'],
            ['resend_webhooks', 'Повторная отправка вебхуков'],
            ['view_users', 'Просмотр пользователей'],
            ['manage_users', 'Управление пользователями'],
            ['view_audit', 'Просмотр аудита'],
            ['manage_settings', 'Управление настройками'],
            ['view_analytics', 'Просмотр аналитики'],
        ];

        $permIds = [];
        foreach ($perms as [$slug, $name]) {
            $existing = DB::table('permissions')->where('slug', $slug)->value('id');
            $permIds[$slug] = $existing !== null
                ? (int) $existing
                : (int) DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'slug' => $slug,
                ]);
        }

        // role_permissions: role_id, permission_id — PK-пары, повтор глушим.
        $rolePerms = [
            $adminRoleId => array_values($permIds),
            $managerRoleId => array_map(
                fn (string $slug): int => $permIds[$slug],
                ['view_events', 'create_events', 'edit_events', 'view_orders', 'edit_orders',
                 'view_payments', 'view_tickets', 'checkin_tickets', 'view_users', 'view_analytics']
            ),
            $supportRoleId => array_map(
                fn (string $slug): int => $permIds[$slug],
                ['view_events', 'view_orders', 'view_payments', 'view_tickets', 'checkin_tickets', 'view_users']
            ),
        ];

        foreach ($rolePerms as $roleId => $ids) {
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // Организация. public_id — ULID-совместимая строка char(26); генерим
        // только при вставке, иначе повторный запуск менял бы публичный
        // идентификатор и ломал уже выданные ссылки.
        $orgId = DB::table('organizations')->where('slug', 'nabilet-demo')->value('id');
        if ($orgId === null) {
            $orgId = DB::table('organizations')->insertGetId([
                'public_id' => (string) Str::ulid()->toBase32(),
                'name' => 'NABILET Demo',
                'slug' => 'nabilet-demo',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $orgId = (int) $orgId;

        // users: id, public_id, email, password, first_name, last_name, status,
        // locale, timezone, email_verified_at, created_at, updated_at
        $accounts = [
            ['admin@nabilet.local', 'admin123', 'Admin', 'User', $adminRoleId],
            ['manager@nabilet.local', 'manager123', 'Manager', 'User', $managerRoleId],
            ['support@nabilet.local', 'support123', 'Support', 'User', $supportRoleId],
        ];

        $adminId = null;
        foreach ($accounts as [$email, $password, $first, $last, $roleId]) {
            $userId = DB::table('users')->where('email', $email)->value('id');

            if ($userId === null) {
                $userId = DB::table('users')->insertGetId([
                    'public_id' => (string) Str::ulid()->toBase32(),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'first_name' => $first,
                    'last_name' => $last,
                    'status' => 'active',
                    'locale' => 'ru',
                    'timezone' => 'Europe/Moscow',
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $userId = (int) $userId;

            $adminId ??= $userId;

            // Членство — каноничная таблица (PK user_id+organization_id).
            DB::table('user_organization')->insertOrIgnore([
                'user_id' => $userId,
                'organization_id' => $orgId,
                'role_id' => $roleId,
                'created_at' => now(),
            ]);

            // user_roles: user_id, organization_id, role_id, granted_by, created_at
            DB::table('user_roles')->insertOrIgnore([
                'user_id' => $userId,
                'organization_id' => $orgId,
                'role_id' => $roleId,
                'granted_by' => $adminId,
                'created_at' => now(),
            ]);
        }

        echo "NABILET admin panel seeded:\n";
        echo "  Admin:   admin@nabilet.local / admin123\n";
        echo "  Manager: manager@nabilet.local / manager123\n";
        echo "  Support: support@nabilet.local / support123\n";
    }
}
