<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Упрощение модели прав: остаётся одна роль `admin`.
 *
 * Роль `manager` ничем не отличалась от `admin` (обе проходили middleware
 * `admin`), но создавала иллюзию разделения прав и была главным вектором
 * эскалации: manager мог назначить себе admin. Установщики и сидеры теперь
 * создают только admin.
 *
 * Существующие manager-назначения НЕ удаляются молча: они переводятся в admin
 * (иначе люди потеряют доступ). Дублирующие строки, если admin у того же
 * пользователя в той же организации уже был, отбрасываются.
 */
return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('roles')->where('slug', 'admin')->value('id');
        $manager = DB::table('roles')->where('slug', 'manager')->first();

        if ($adminId === null || $manager === null) {
            return;
        }

        $managerId = (int) $manager->id;
        $now = now();

        // user_roles: перенести отсутствующие пары (user, org, admin).
        $rows = DB::table('user_roles')->where('role_id', $managerId)->get();
        foreach ($rows as $row) {
            $exists = DB::table('user_roles')
                ->where('user_id', $row->user_id)
                ->where('organization_id', $row->organization_id)
                ->where('role_id', $adminId)
                ->exists();
            if (! $exists) {
                DB::table('user_roles')->insert([
                    'user_id' => $row->user_id,
                    'organization_id' => $row->organization_id,
                    'role_id' => $adminId,
                    'granted_by' => $row->granted_by ?? null,
                    'created_at' => $row->created_at ?? $now,
                ]);
            }
        }
        DB::table('user_roles')->where('role_id', $managerId)->delete();

        // user_organization: роль в членстве тоже должна стать admin.
        DB::table('user_organization')->where('role_id', $managerId)->update(['role_id' => $adminId]);

        // Дубли членств с разными ролями у одного (user, org) — оставляем admin.
        $duplicates = DB::table('user_organization')
            ->select('user_id', 'organization_id')
            ->groupBy('user_id', 'organization_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $d) {
            $keep = DB::table('user_organization')
                ->where('user_id', $d->user_id)
                ->where('organization_id', $d->organization_id)
                ->where('role_id', $adminId)
                ->orderBy('id')
                ->value('id');
            $keep ??= DB::table('user_organization')
                ->where('user_id', $d->user_id)
                ->where('organization_id', $d->organization_id)
                ->orderBy('id')
                ->value('id');
            DB::table('user_organization')
                ->where('user_id', $d->user_id)
                ->where('organization_id', $d->organization_id)
                ->where('id', '!=', $keep)
                ->delete();
        }

        // Удаляем саму роль manager (FK restrict — только после переноса ссылок).
        DB::table('role_permissions')->where('role_id', $managerId)->delete();
        DB::table('roles')->where('id', $managerId)->delete();
    }

    public function down(): void
    {
        // Роль manager намеренно не восстанавливается.
    }
};
