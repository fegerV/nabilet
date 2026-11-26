<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Приоритетные риски из docs/audit/REMAINING-ISSUES.md: AUD-020/021/022.
 */
final class StabilizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $orgA;
    private int $orgB;
    private int $sessionB;

    protected function setUp(): void
    {
        parent::setUp();
        // Тест изоляции: явно выключаем тестовый одноарендаторский режим.
        config()->set('nabilet.single_tenant_admin', false);
        config()->set('nabilet.platform_admins', []);
        $this->orgA = $this->org('Организация A');
        $this->orgB = $this->org('Организация B');
        $eventB = $this->event($this->orgB, 'published');
        $venueId = $this->venue($this->orgB);
        $schemaId = $this->schema($venueId, $this->orgB);
        $this->sessionB = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->orgB,
            'event_id' => $eventB,
            'venue_id' => $venueId,
            'hall_id' => $venueId,
            'schema_version_id' => $schemaId,
            'starts_at' => '2026-12-20 19:00:00',
            'timezone' => 'UTC',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_empty_platform_admin_list_denies_global_settings(): void
    {
        config()->set('nabilet.platform_admins', []);
        config()->set('nabilet.single_tenant_admin', false);
        $this->actingAs($this->user($this->orgA, 'a@example.test'), 'api');

        $this->getJson('/api/v1/admin/backups/settings')->assertForbidden();
    }

    public function test_inventory_of_a_foreign_session_is_not_exposed(): void
    {
        $this->actingAs($this->user($this->orgA, 'a@example.test'), 'api');

        $this->getJson('/api/v1/inventory?session_id=' . $this->sessionB)->assertNotFound();
        $this->getJson('/api/v1/inventory/sessions/' . $this->sessionB . '/availability')->assertNotFound();
    }

    public function test_guest_cannot_see_inventory_of_an_unpublished_event(): void
    {
        DB::table('events')->where('id', (int) DB::table('sessions')->where('id', $this->sessionB)->value('event_id'))
            ->update(['status' => 'draft']);

        $this->getJson('/api/v1/inventory?session_id=' . $this->sessionB)->assertNotFound();
    }

    public function test_organization_list_and_detail_are_limited_to_membership(): void
    {
        $this->actingAs($this->user($this->orgA, 'a@example.test'), 'api');

        $response = $this->getJson('/api/v1/organizations?limit=100')->assertOk();
        $ids = collect($response->json('data'))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $this->assertContains($this->orgA, $ids);
        $this->assertNotContains($this->orgB, $ids);

        $publicB = (string) DB::table('organizations')->where('id', $this->orgB)->value('public_id');
        // Middleware CheckOrganizationAccess уже отсекает чужую организацию.
        $this->getJson('/api/v1/organizations/' . $publicB)->assertForbidden();
    }

    private function org(string $name): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(int $organizationId, string $email): User
    {
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $organizationId,
            'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $organizationId,
            'role_id' => $roleId, 'created_at' => $now,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function event(int $organizationId, string $status): int
    {
        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Событие',
            'slug' => 'event-' . Str::random(8),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function venue(int $organizationId): int
    {
        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Зал',
            'slug' => 'venue-' . Str::random(8),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function schema(int $venueId, int $organizationId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'hall_id' => $venueId,
            'venue_id' => $venueId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'schema_json' => json_encode(['sectors' => []]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
