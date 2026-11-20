<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Вторая волна аудита: деньги, приватность и глобальные операции.
 *
 * Каждый тест — регрессия на конкретный способ обойти проверку, а не просто
 * «проверка статуса». Держим их рядом, потому что все четыре выросли из одного
 * отчёта и их удобно прогонять одной командой перед релизом.
 */
class AuditHardeningWave2Test extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── приватность витрины ────────────────────────────────────────────────

    public function test_draft_event_is_not_exposed_by_public_json(): void
    {
        [, , $sessionId] = $this->seedSellableSeat(available: 1, priceAmount: 1_000_00);
        [$eventId, $slug] = $this->draftEvent($sessionId);

        $this->getJson("/api/v1/events/{$eventId}")->assertNotFound();
        $this->getJson("/api/v1/events/by-slug/{$slug}")->assertNotFound();
        $this->getJson('/api/v1/sessions?event_id='.$eventId)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
        $this->getJson("/api/v1/sessions/{$sessionId}")->assertNotFound();
    }

    public function test_staff_still_sees_a_draft_event(): void
    {
        [, , $sessionId] = $this->seedSellableSeat(available: 1, priceAmount: 1_000_00);
        [$eventId, $slug] = $this->draftEvent($sessionId);

        $this->actingAs($this->admin(), 'api');

        $this->getJson("/api/v1/events/{$eventId}")->assertOk();
        $this->getJson("/api/v1/events/by-slug/{$slug}")->assertOk();
    }

    // ── глобальные операции ────────────────────────────────────────────────

    public function test_global_settings_require_a_platform_admin_when_the_list_is_configured(): void
    {
        config()->set('nabilet.platform_admins', ['platform@example.com']);

        $this->actingAs($this->admin('tenant-admin@example.com'), 'api');
        $this->getJson('/api/v1/admin/backups/settings')->assertForbidden();

        $this->actingAs($this->admin('platform@example.com'), 'api');
        $this->getJson('/api/v1/admin/backups/settings')->assertOk();
    }

    public function test_platform_fallback_keeps_single_tenant_installations_working(): void
    {
        config()->set('nabilet.platform_admins', []);

        $admin = $this->admin('tenant-admin@example.com');
        $this->actingAs($admin, 'api');

        self::assertTrue(StaffRole::isPlatformAdmin($admin));
        $this->getJson('/api/v1/admin/backups/settings')->assertOk();
    }

    // ── фикстуры ───────────────────────────────────────────────────────────

    /** @return array{0: int, 1: string} id и slug события, переведённого в черновик */
    private function draftEvent(int $sessionId): array
    {
        $eventId = (int) DB::table('sessions')->where('id', $sessionId)->value('event_id');
        DB::table('events')->where('id', $eventId)->update(['status' => 'draft']);

        return [$eventId, (string) DB::table('events')->where('id', $eventId)->value('slug')];
    }

    private function admin(string $email = 'admin@example.com'): User
    {
        $now = now();
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org '.$email,
            'slug' => 'org-'.Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
}
