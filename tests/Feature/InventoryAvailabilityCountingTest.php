<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * GET /inventory/sessions/{id}/availability: билеты, а не строки склада.
 *
 * Повод — аудит: эндпоинт отдавал `count()` строк `inventory_items`. У сидячего
 * места вместимость 1, поэтому для чисто сидячего зала это совпадало с числом
 * билетов и ошибка не бросалась в глаза. Но стоячая зона — ОДНА строка с
 * `capacity = N`, поэтому зал «280 мест + танцпол на 150» показывал 281 билет
 * вместо 430. Проверяем на минимальном воспроизведении: 2 места + зона на 150.
 */
final class InventoryAvailabilityCountingTest extends TestCase
{
    use DatabaseTransactions;

    private Hall $hall;

    private HallSchemaVersion $version;

    private Session $session;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Availability ' . Str::random(10),
            'slug' => 'availability-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'settings_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'name' => 'Availability venue',
            'slug' => 'availability-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Mixed hall',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->hall = Hall::query()->findOrFail($hallId);

        $this->version = HallSchemaVersion::create([
            'hall_id' => $this->hall->id,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'schema_json' => [
                'version' => '1.0',
                'canvas' => ['width' => 900, 'height' => 620],
                'sectors' => [[
                    'id' => 'sec-parter', 'name' => 'Партер', 'x' => 100, 'y' => 100,
                    'priceMinor' => 100000, 'type' => 'seated', 'shape' => 'grid', 'rowPrices' => [],
                    'seats' => [
                        ['id' => 's1', 'row' => 1, 'number' => 1, 'kind' => 'standard', 'x' => 0, 'y' => 0],
                        ['id' => 's2', 'row' => 1, 'number' => 2, 'kind' => 'standard', 'x' => 22, 'y' => 0],
                    ],
                ]],
                'staticObjects' => [[
                    'id' => 'zone-1', 'kind' => 'standing', 'x' => 300, 'y' => 380,
                    'width' => 300, 'height' => 100, 'text' => 'Танцпол', 'capacity' => 150, 'priceMinor' => 50000,
                ]],
            ],
        ]);

        $eventId = (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'title' => 'Availability event',
            'slug' => 'availability-' . Str::lower(Str::random(12)),
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => $eventId,
            'venue_id' => $venueId,
            'hall_id' => $this->hall->id,
            'schema_version_id' => $this->version->id,
            'starts_at' => $now->copy()->addDays(7),
            'status' => 'on_sale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->session = Session::query()->findOrFail($sessionId);
    }

    /** @return array<string, mixed> */
    private function availability(): array
    {
        $response = $this->getJson("/api/v1/inventory/sessions/{$this->session->id}/availability");
        $response->assertOk();

        return $response->json('data');
    }

    public function test_standing_zone_capacity_is_counted_in_tickets_not_as_one_position(): void
    {
        $created = app(InventoryService::class)->generateFromSchema($this->version, $this->session);
        self::assertSame(3, $created, '2 места + 1 стоячая зона = 3 позиции склада');

        $data = $this->availability();

        // Позиции склада — ровно то, что эндпоинт отдавал раньше.
        self::assertSame(3, $data['positions']['total']);
        self::assertSame(3, $data['positions']['available']);

        // Билеты — реальная вместимость: 2 места + 150 стоячих.
        self::assertSame(152, $data['tickets']['capacity']);
        self::assertSame(152, $data['tickets']['available']);
        self::assertSame(0, $data['tickets']['reserved']);
        self::assertSame(0, $data['tickets']['sold']);
        self::assertFalse($data['tickets']['sold_out']);
    }

    public function test_reserved_tickets_are_subtracted_from_available(): void
    {
        app(InventoryService::class)->generateFromSchema($this->version, $this->session);

        $standing = InventoryItem::query()
            ->where('session_id', $this->session->id)
            ->where('type', 'standing')
            ->firstOrFail();

        // 3 билета разобрала корзина (именно так это делает CartItemService).
        $standing->decrement('available_quantity', 3);

        $data = $this->availability();

        self::assertSame(149, $data['tickets']['available']);
        self::assertSame(3, $data['tickets']['reserved']);
        self::assertSame(152, $data['tickets']['capacity']);
        self::assertFalse($data['tickets']['sold_out']);
    }

    public function test_sold_out_is_true_only_when_no_tickets_remain(): void
    {
        app(InventoryService::class)->generateFromSchema($this->version, $this->session);

        InventoryItem::query()->where('session_id', $this->session->id)->update(['available_quantity' => 0]);

        $data = $this->availability();

        self::assertSame(0, $data['tickets']['available']);
        self::assertTrue($data['tickets']['sold_out']);
        // Резерв не уходит в минус даже если билеты уже проданы, а позиции ещё
        // не помечены 'sold'.
        self::assertSame(152, $data['tickets']['reserved']);
    }

    public function test_reserved_never_goes_negative(): void
    {
        app(InventoryService::class)->generateFromSchema($this->version, $this->session);

        // Позиции помечены проданными (так делает PaymentService), но билетов
        // в базе нет — вычитание capacity - available - sold дало бы минус.
        InventoryItem::query()->where('session_id', $this->session->id)
            ->update(['status' => 'sold', 'available_quantity' => 0]);

        $data = $this->availability();

        self::assertSame(0, $data['tickets']['available']);
        self::assertGreaterThanOrEqual(0, $data['tickets']['reserved']);
        self::assertSame(3, $data['positions']['sold']);
        self::assertSame(0, $data['positions']['available']);
    }
}
