<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Атомарность «сеанс + инвентарь» (аудит 2026-11-25, дефект A1/A2/A3).
 *
 * До исправления `SessionController` коммитил сеанс до вызова генератора.
 * Ошибка генерации оставляла сеанс без мест, а при смене схемы — сеанс с новой
 * схемой и старым инвентарём. Эти тесты проверяют СОСТОЯНИЕ БД после ошибки,
 * а не только код ответа.
 */
final class SessionInventoryAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;
    private int $eventId;
    private int $firstHallId;
    private int $firstSchemaId;
    private int $secondHallId;
    private int $secondSchemaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin($this->organizationId);
        $this->eventId = $this->seedEvent($this->organizationId);

        $this->firstHallId = $this->seedVenue('Зал №1');
        $this->firstSchemaId = $this->seedSchema($this->firstHallId, $this->seatedSchema());
        $this->secondHallId = $this->seedVenue('Зал №2');
        $this->secondSchemaId = $this->seedSchema($this->secondHallId, $this->seatedSchema());
    }

    public function test_generation_failure_rolls_back_the_created_session(): void
    {
        $this->mock(InventoryService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('generateFromSchema')
                ->once()
                ->andThrow(new \RuntimeException('искусственная ошибка генерации'));
        });

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/sessions', [
                'event_id' => $this->eventId,
                'hall_id' => $this->firstHallId,
                'venue_id' => $this->firstHallId,
                'starts_at' => '2026-12-01 19:00',
            ]);
            $this->fail('ожидалась ошибка генерации инвентаря');
        } catch (\RuntimeException $e) {
            $this->assertSame('искусственная ошибка генерации', $e->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame(
            0,
            (int) DB::table('sessions')->count(),
            'сеанс без инвентаря остался в БД после ошибки генерации'
        );
    }

    public function test_failed_rebuild_keeps_the_old_schema_and_inventory(): void
    {
        $sessionId = $this->createSession();
        $itemsBefore = (int) DB::table('inventory_items')->where('session_id', $sessionId)->count();
        $this->assertGreaterThan(0, $itemsBefore);

        // Мокаем ТОЛЬКО после первого сеанса: первый вызов генератора должен
        // отработать по-настоящему и материализовать инвентарь.
        $this->mock(InventoryService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('assertRegenerable')->andReturnNull();
            $mock->shouldReceive('generateFromSchema')
                ->once()
                ->andThrow(new \RuntimeException('искусственная ошибка пересборки'));
        });

        $this->withoutExceptionHandling();

        try {
            $this->patchJson("/api/v1/sessions/{$sessionId}", [
                'hall_id' => $this->secondHallId,
                'venue_id' => $this->secondHallId,
                'schema_version_id' => $this->secondSchemaId,
            ]);
            $this->fail('ожидалась ошибка пересборки инвентаря');
        } catch (\RuntimeException) {
            // Ожидаемо.
        } finally {
            $this->withExceptionHandling();
        }

        $session = DB::table('sessions')->where('id', $sessionId)->firstOrFail();
        $this->assertSame($this->firstSchemaId, (int) $session->schema_version_id);
        $this->assertSame($this->firstHallId, (int) $session->hall_id);
        $this->assertSame(
            $itemsBefore,
            (int) DB::table('inventory_items')->where('session_id', $sessionId)->count(),
            'инвентарь был изменён до ошибки пересборки'
        );
    }

    public function test_stale_hold_does_not_block_rebuild_but_an_active_hold_does(): void
    {
        $sessionId = $this->createSession();
        $itemId = (int) DB::table('inventory_items')
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->value('id');

        $this->insertStaleHold($sessionId, $itemId);

        // Снятая (просроченная) держка больше не блокирует смену схемы.
        $this->patchJson("/api/v1/sessions/{$sessionId}", [
            'hall_id' => $this->secondHallId,
            'venue_id' => $this->secondHallId,
            'schema_version_id' => $this->secondSchemaId,
        ])->assertStatus(200);

        $session = DB::table('sessions')->where('id', $sessionId)->firstOrFail();
        $this->assertSame($this->secondSchemaId, (int) $session->schema_version_id);
        $this->assertSame(0, (int) DB::table('seat_holds')->where('session_id', $sessionId)->count(),
            'снятая держка не была удалена перед пересборкой');

        // ПОСЛЕ пересборки создаём живую держку на другой сеанс той же второй
        // схемы и проверяем, что она честно блокирует следующую пересборку.
        $otherSessionId = $this->createSession('2026-12-05 19:00', $this->secondHallId);
        $otherItemId = (int) DB::table('inventory_items')
            ->where('session_id', $otherSessionId)
            ->orderBy('id')
            ->value('id');
        $this->insertActiveHold($otherSessionId, $otherItemId);

        $this->patchJson("/api/v1/sessions/{$otherSessionId}", [
            'hall_id' => $this->firstHallId,
            'venue_id' => $this->firstHallId,
            'schema_version_id' => $this->firstSchemaId,
        ])->assertStatus(409)->assertJsonPath('error.code', 'SESSION_SCHEMA_HELD');

        $otherSession = DB::table('sessions')->where('id', $otherSessionId)->firstOrFail();
        $this->assertSame($this->secondSchemaId, (int) $otherSession->schema_version_id,
            'при 409 сеанс всё же сменил схему');
    }

    // ── Помощники ───────────────────────────────────────────────────────────

    private function createSession(string $startsAt = '2026-12-01 19:00', ?int $hallId = null): int
    {
        $hallId ??= $this->firstHallId;

        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'hall_id' => $hallId,
            'venue_id' => $hallId,
            'starts_at' => $startsAt,
        ]);
        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** @return array<string, mixed> */
    private function seatedSchema(): array
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
                    'seats' => [
                        ['number' => '1', 'label' => 'Место 1', 'type' => 'standard'],
                        ['number' => '2', 'label' => 'Место 2', 'type' => 'standard'],
                    ],
                ]],
            ]],
        ];
    }

    private function insertStaleHold(int $sessionId, int $itemId): void
    {
        $cartId = $this->insertCart($sessionId);
        DB::table('cart_items')->insert([
            'organization_id' => $this->organizationId,
            'cart_id' => $cartId,
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => 100_000,
            'total_price' => 100_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('seat_holds')->insert([
            'organization_id' => $this->organizationId,
            'public_id' => (string) Str::ulid()->toBase32(),
            'inventory_item_id' => $itemId,
            'session_id' => $sessionId,
            'cart_id' => $cartId,
            'quantity' => 1,
            'expires_at' => now()->subHour(),
            'released_at' => now()->subMinutes(30),
            'created_at' => now(),
        ]);
    }

    private function insertActiveHold(int $sessionId, int $itemId): void
    {
        $cartId = $this->insertCart($sessionId);
        DB::table('cart_items')->insert([
            'organization_id' => $this->organizationId,
            'cart_id' => $cartId,
            'inventory_item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => 100_000,
            'total_price' => 100_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('seat_holds')->insert([
            'organization_id' => $this->organizationId,
            'public_id' => (string) Str::ulid()->toBase32(),
            'inventory_item_id' => $itemId,
            'session_id' => $sessionId,
            'cart_id' => $cartId,
            'quantity' => 1,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);
    }

    private function insertCart(int $sessionId): int
    {
        return (int) DB::table('carts')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'session_id' => $sessionId,
            'status' => 'active',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Тестовая организация',
            'slug' => 'audit-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedEvent(int $organizationId): int
    {
        $now = now();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Концерт для аудита',
            'slug' => 'audit-concert-' . Str::random(6),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVenue(string $name): int
    {
        $now = now();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'name' => $name,
            'slug' => 'audit-venue-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $schema */
    private function seedSchema(int $hallId, array $schema): int
    {
        $version = HallSchemaVersion::create([
            'hall_id' => $hallId,
            'venue_id' => $hallId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => now(),
            'schema_json' => $schema,
        ]);

        return (int) $version->id;
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
