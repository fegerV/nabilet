<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Межорганизационная изоляция конструктора (аудит 2026-11-25, I-1/I-3/I-4/I-6).
 *
 * Две НЕЗАВИСИМЫЕ организации. Проверяем не «код ответа вообще», а отсутствие
 * чужих идентификаторов в ответе: черновик/архив схемы и сеанс другой организации
 * обязаны быть неотличимы от несуществующих.
 */
final class TenantIsolationHallSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $orgA;
    private int $orgB;
    private User $adminA;
    private User $adminB;
    private string $venueAPublicId;
    private string $venueBPublicId;
    private int $draftBId;
    private int $publishedBId;
    private int $sessionAId;
    private int $sessionBId;
    private int $eventAId;
    private int $eventBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = $this->seedOrganization('Организация A');
        $this->orgB = $this->seedOrganization('Организация B');
        $this->adminA = $this->seedAdmin($this->orgA);
        $this->adminB = $this->seedAdmin($this->orgB);

        $this->eventAId = $this->seedEvent($this->orgA, 'Концерт A');
        $this->eventBId = $this->seedEvent($this->orgB, 'Концерт B');

        $venueAId = $this->seedVenue($this->orgA, 'Площадка A');
        $venueBId = $this->seedVenue($this->orgB, 'Площадка B');
        $this->venueAPublicId = (string) DB::table('venues')->where('id', $venueAId)->value('public_id');
        $this->venueBPublicId = (string) DB::table('venues')->where('id', $venueBId)->value('public_id');

        $this->publishedBId = $this->seedSchema($venueBId, 'published', 1);
        $this->draftBId = $this->seedSchema($venueBId, 'draft', 2);

        $publishedAId = $this->seedSchema($venueAId, 'published', 1);
        $this->sessionAId = $this->seedSession($this->orgA, $this->eventAId, $venueAId, $publishedAId);
        $this->sessionBId = $this->seedSession($this->orgB, $this->eventBId, $venueBId, $this->publishedBId);
    }

    public function test_the_owner_still_sees_its_own_draft_and_halls(): void
    {
        $this->actingAs($this->adminB, 'api');

        $this->getJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions/{$this->draftBId}")
            ->assertOk();

        $this->actingAs($this->adminA, 'api');
        $this->getJson("/api/v1/venues/{$this->venueAPublicId}/schema-versions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_of_another_organization_cannot_read_a_foreign_draft_version(): void
    {
        $this->actingAs($this->adminA, 'api');

        $this->getJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions")
            ->assertOk()
            ->assertJsonMissing(['id' => $this->draftBId])
            ->assertJsonPath('data.0.status', 'published');

        $this->getJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions/{$this->draftBId}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HALLSCHEMAVERSION_NOT_FOUND');
    }

    public function test_guest_cannot_read_a_foreign_draft_version(): void
    {
        $this->getJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions")
            ->assertOk()
            ->assertJsonMissing(['id' => $this->draftBId]);

        $this->getJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions/{$this->draftBId}")
            ->assertStatus(404);
    }

    public function test_admin_of_another_organization_cannot_write_a_foreign_venue_schema(): void
    {
        $this->actingAs($this->adminA, 'api');

        $this->postJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions/draft", [
            'payload' => ['sectors' => [], 'staticObjects' => []],
        ])->assertStatus(404);

        $this->postJson("/api/v1/venues/{$this->venueBPublicId}/schema-versions/{$this->publishedBId}/publish", ['base_revision' => 1])
            ->assertStatus(404);
    }

    public function test_staff_session_list_and_detail_are_scoped_to_the_organization(): void
    {
        $this->actingAs($this->adminA, 'api');

        $response = $this->getJson('/api/v1/sessions')->assertOk();
        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $this->assertContains($this->sessionAId, $ids);
        $this->assertNotContains($this->sessionBId, $ids, 'список сеансов admin A содержит сеанс admin B');

        $this->getJson("/api/v1/sessions/{$this->sessionBId}")->assertStatus(404);
    }

    public function test_the_all_halls_catalog_is_no_longer_public(): void
    {
        $this->getJson('/api/v1/halls')->assertStatus(401);

        $this->actingAs($this->adminA, 'api');
        $response = $this->getJson('/api/v1/halls')->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('Площадка B', $names, 'admin A видит залы другой организации');
        $this->assertContains('Площадка A', $names);
    }

    // ── Помощники ───────────────────────────────────────────────────────────

    private function seedOrganization(string $name): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedAdmin(int $organizationId): User
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

        return User::query()->findOrFail($userId);
    }

    private function seedEvent(int $organizationId, string $title): int
    {
        $now = now();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(6),
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVenue(int $organizationId, string $name): int
    {
        $now = now();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'sectors' => [[
                'name' => 'Партер',
                'code' => 'P',
                'type' => 'seated',
                'rows' => [[
                    'number' => '1',
                    'label' => 'Ряд 1',
                    'price_amount' => 100_000,
                    'seats' => [['number' => '1', 'type' => 'standard']],
                ]],
            ]],
        ];
    }

    private function seedSchema(int $venueId, string $status, int $version): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => (int) DB::table('venues')->where('id', $venueId)->value('organization_id'),
            'hall_id' => $venueId,
            'venue_id' => $venueId,
            'version' => $version,
            'revision' => 1,
            'status' => $status,
            'published_at' => $status === 'published' ? $now : null,
            'schema_json' => json_encode($this->payload()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedSession(int $organizationId, int $eventId, int $venueId, int $schemaId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'event_id' => $eventId,
            'venue_id' => $venueId,
            'hall_id' => $venueId,
            'schema_version_id' => $schemaId,
            'starts_at' => '2026-12-20 19:00:00',
            'timezone' => 'UTC',
            'status' => 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
