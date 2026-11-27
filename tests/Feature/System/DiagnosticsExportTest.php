<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Экспорт диагностики для поддержки: только админ, без секретов, без кеша.
 */
final class DiagnosticsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_admin_only_and_anonymous(): void
    {
        $this->getJson('/api/v1/admin/system/diagnostics/export')->assertUnauthorized();
        $this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/admin/system/diagnostics/export')
            ->assertOk();
    }

    public function test_export_contains_sanitized_report_only(): void
    {
        $response = $this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/admin/system/diagnostics/export')
            ->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('application', $body);
        $this->assertStringNotContainsString('APP_KEY', $body);
        $this->assertStringNotContainsString('NABILET_UPDATE_PRIVATE_KEY', $body);
        $this->assertStringNotContainsString('yookassa_secret_key', $body);
    }

    private function admin(): User
    {
        $now = now()->toDateTimeString();
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        $orgId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Export org',
            'slug' => 'export-' . Str::random(5),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'export-' . Str::random(5) . '@example.test',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        return $user;
    }
}
