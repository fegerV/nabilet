<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Завершение миграции halls → venues (аудит 2026-11-25, P1 4.1).
 *
 * `halls` — VIEW, поэтому любые UPDATE через Hall/VIEW падали 500, а поля
 * вместимости и размеров вообще не имели колонок. Проверяем, что админский
 * UPDATE попадает в venues и виден через VIEW, а удаление площадки с
 * замороженной схемой остановлено и приложением, и БД.
 */
final class HallViewWriteTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin($this->organizationId);
    }

    public function test_hall_update_writes_the_real_venue_table_and_is_visible_through_the_view(): void
    {
        $venue = $this->postJson('/api/v1/venues', ['name' => 'Театр на VIEW'])->assertCreated();
        $hallPublicId = (string) $venue->json('hall.public_id');
        $venueId = (int) $venue->json('data.id');

        $this->putJson("/api/v1/halls/{$hallPublicId}", [
            'name' => 'Большой зал',
            'description' => 'Схема на площадке',
            'capacity' => 812,
            'width' => 900,
            'height' => 620,
        ])->assertOk();

        $this->assertDatabaseHas('venues', [
            'id' => $venueId,
            'hall_name' => 'Большой зал',
            'capacity' => 812,
            'width' => 900,
            'height' => 620,
        ]);
        $this->assertDatabaseHas('halls', [
            'id' => $venueId,
            'name' => 'Большой зал',
            'capacity' => 812,
        ]);
    }

    public function test_venue_with_a_published_schema_cannot_be_deleted(): void
    {
        $venue = $this->postJson('/api/v1/venues', ['name' => 'Историческая площадка'])->assertCreated();
        $venueId = (int) $venue->json('data.id');
        $publicId = (string) $venue->json('data.public_id');

        HallSchemaVersion::create([
            'hall_id' => $venueId,
            'venue_id' => $venueId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => now(),
            'schema_json' => ['sectors' => []],
        ]);

        $this->deleteJson("/api/v1/venues/{$publicId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VENUE_HAS_FROZEN_SCHEMA_VERSIONS');
        $this->assertDatabaseHas('venues', ['id' => $venueId]);

        // Второй барьер — триггер БД: обойти проверку приложения прямым DELETE
        // нельзя, иначе исторические схемы/билеты останутся осиротевшими.
        try {
            DB::table('venues')->where('id', $venueId)->delete();
            $this->fail('ожидался отказ БД-триггера на удаление площадки');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('45000', $e->getMessage());
        }
        $this->assertDatabaseHas('venues', ['id' => $venueId]);
    }

    // ── Помощники ───────────────────────────────────────────────────────────

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Владелец зала',
            'slug' => 'hall-view-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function actingAsAdmin(int $organizationId): User
    {
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
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
        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }
}
