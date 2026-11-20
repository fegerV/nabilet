<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Второй барьер БД виден администратору, но не ломает публичный health.
 *
 * На TimeWeb с MySQL 5.7 CHECK не исполняются, а CREATE TRIGGER часто
 * отклоняется. Это ограничение хостинга, а не отказ узла: /health остаётся в
 * контракте, а админ и самопроверка видят отдельный диагностический сигнал.
 */
class DatabaseInvariantsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_status_exposes_the_database_invariants_check(): void
    {
        $this->actingAs($this->admin(), 'api');

        $response = $this->getJson('/api/v1/admin/system/status')->assertOk();

        $check = $response->json('checks.database_invariants');
        $this->assertIsArray($check);
        $this->assertContains($check['status'], ['ok', 'warning', 'error']);
        $this->assertIsString($check['message']);
        $this->assertNotSame('Проверка не пройдена', $check['message']);
    }

    public function test_public_health_keeps_the_spec_shape(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertArrayNotHasKey('checks', $response->json());
        $this->assertContains($response->json('status'), ['ok', 'degraded', 'down']);
    }

    private function admin(): User
    {
        $now = now();
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        $orgId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-koncert',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'admin@example.com',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $orgId,
            'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $orgId,
            'role_id' => $roleId, 'created_at' => $now,
        ]);

        return User::query()->findOrFail($userId);
    }
}
