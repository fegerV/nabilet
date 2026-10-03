<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class NabiletAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Roles - these tables have NO timestamps
        // roles: id, name, slug, description
        $adminRoleId = DB::table('roles')->insertGetId([
            'name' => 'Администратор',
            'slug' => 'admin',
            'description' => 'Полный доступ к системе',
        ]);
        $managerRoleId = DB::table('roles')->insertGetId([
            'name' => 'Менеджер',
            'slug' => 'manager',
            'description' => 'Управление мероприятиями и заказами',
        ]);
        $supportRoleId = DB::table('roles')->insertGetId([
            'name' => 'Саппорт',
            'slug' => 'support',
            'description' => 'Просмотр данных, управление билетами',
        ]);

        // Permissions: id, name, slug
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
            $permIds[$slug] = DB::table('permissions')->insertGetId([
                'name' => $name,
                'slug' => $slug,
            ]);
        }

        // role_permissions: role_id, permission_id
        foreach ($permIds as $id) {
            DB::table('role_permissions')->insert([
                'role_id' => $adminRoleId,
                'permission_id' => $id,
            ]);
        }

        // Manager permissions
        $managerPerms = ['view_events', 'create_events', 'edit_events', 'view_orders', 'edit_orders', 'view_payments', 'view_tickets', 'checkin_tickets', 'view_users', 'view_analytics'];
        foreach ($managerPerms as $slug) {
            DB::table('role_permissions')->insert([
                'role_id' => $managerRoleId,
                'permission_id' => $permIds[$slug],
            ]);
        }

        // Support permissions
        $supportPerms = ['view_events', 'view_orders', 'view_payments', 'view_tickets', 'checkin_tickets', 'view_users'];
        foreach ($supportPerms as $slug) {
            DB::table('role_permissions')->insert([
                'role_id' => $supportRoleId,
                'permission_id' => $permIds[$slug],
            ]);
        }

        // Create organization
        DB::table('organizations')->insert([
            'public_id' => substr(bin2hex(random_bytes(13)), 0, 26),
            'name' => 'NABILET Demo',
            'slug' => 'nabilet-demo',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = DB::table('organizations')->where('slug', 'nabilet-demo')->value('id');

        // users: id, public_id, email, password, first_name, last_name, status, locale, timezone, email_verified_at, created_at, updated_at
        $adminId = DB::table('users')->insertGetId([
            'public_id' => substr(bin2hex(random_bytes(13)), 0, 26),
            'email' => 'admin@nabilet.local',
            'password' => Hash::make('admin123'),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'Europe/Moscow',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managerId = DB::table('users')->insertGetId([
            'public_id' => substr(bin2hex(random_bytes(13)), 0, 26),
            'email' => 'manager@nabilet.local',
            'password' => Hash::make('manager123'),
            'first_name' => 'Manager',
            'last_name' => 'User',
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'Europe/Moscow',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supportId = DB::table('users')->insertGetId([
            'public_id' => substr(bin2hex(random_bytes(13)), 0, 26),
            'email' => 'support@nabilet.local',
            'password' => Hash::make('support123'),
            'first_name' => 'Support',
            'last_name' => 'User',
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'Europe/Moscow',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // user_roles: user_id, organization_id, role_id, granted_by, created_at
        foreach ([
            [$adminId, $adminRoleId],
            [$managerId, $managerRoleId],
            [$supportId, $supportRoleId],
        ] as [$uid, $rid]) {
            DB::table('user_roles')->insert([
                'user_id' => $uid,
                'organization_id' => $orgId,
                'role_id' => $rid,
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
