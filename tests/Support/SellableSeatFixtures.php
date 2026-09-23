<?php

declare(strict_types=1);

namespace Nabilet\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Фикстуры для тестов, которым нужен «продаваемый» заказ.
 *
 * Цепочка внешних ключей обязательна целиком, потому что все её звенья NOT NULL:
 *
 *   organizations → events → venues → halls → hall_schema_versions
 *                → sessions → inventory_items
 *
 * Две неочевидные вещи, на которых легко споткнуться:
 *
 *  1. `ck_inventory_target` требует ровно один «якорь» у позиции склада —
 *     `seat_id` XOR `standing_zone_id`. Стоячая зона выбрана потому, что только
 *     она допускает `capacity > 1`: у `type = 'seat'` есть
 *     `ck_inventory_seat_capacity`, ограничивающий вместимость единицей, а на
 *     одном месте нельзя проверить покупку двух билетов.
 *  2. Стоячая зона, в свою очередь, требует сектор, а сектор — версию схемы зала,
 *     поэтому `sectors` создаётся здесь же.
 */
trait SellableSeatFixtures
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: int} [organizationId, eventId, sessionId, inventoryItemId]
     */
    protected function seedSellableSeat(
        int $available,
        int $priceAmount = 5_000_000,
        string $eventTitle = 'Тестовое событие',
    ): array {
        $now = now()->toDateTimeString();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Test Org',
            'slug' => 'test-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $eventId = (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => $eventTitle,
            'slug' => 'event-' . Str::random(6),
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Театр',
            'slug' => 'venue-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $hallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Большой зал',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $schemaVersionId = (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'hall_id' => $hallId,
            'version' => 1,
            'status' => 'published',
            'schema_json' => json_encode(['rows' => []]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => $eventId,
            'venue_id' => $venueId,
            'hall_id' => $hallId,
            'schema_version_id' => $schemaVersionId,
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'timezone' => 'Asia/Almaty',
            'status' => 'on_sale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $standingZoneId = $this->seedStandingZone($schemaVersionId, $priceAmount);

        $inventoryItemId = (int) DB::table('inventory_items')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'session_id' => $sessionId,
            'type' => 'standing',
            'standing_zone_id' => $standingZoneId,
            'price_amount' => $priceAmount,
            'currency' => 'RUB',
            'capacity' => $available,
            'available_quantity' => $available,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$organizationId, $eventId, $sessionId, $inventoryItemId];
    }

    protected function seedStandingZone(int $schemaVersionId, int $priceAmount = 5_000_000): int
    {
        $now = now()->toDateTimeString();

        $sectorId = (int) DB::table('sectors')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'schema_version_id' => $schemaVersionId,
            'name' => 'Партер',
            'code' => 'P-' . Str::random(4),
            'type' => 'standing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('standing_zones')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'sector_id' => $sectorId,
            'name' => 'Танцпол',
            'capacity' => 100,
            'price_amount' => $priceAmount,
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function seedSucceededPayment(int $orderId, int $amount): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('payments')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'order_id' => $orderId,
            'provider' => 'yookassa',
            'provider_payment_id' => 'test-' . Str::random(10),
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::ulid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
