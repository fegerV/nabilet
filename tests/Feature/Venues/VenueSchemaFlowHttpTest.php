<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * HTTP-контур «площадка → зал → схема → сеанс».
 *
 * Это регрессия на жалобы «площадок много, а схем нет» и «конструктор не
 * работает»: до правок админ создавал площадку без зала, шёл на отдельную
 * страницу «Залы», искал площадку в списке и на этом останавливался. Здесь
 * проверяется весь путь через настоящие админские маршруты:
 *
 *   1. `POST /venues` создаёт зал вместе с площадкой (модель Яндекс Афиши);
 *   2. список площадок отдаёт состояние схемы зала (`current_schema_version`),
 *      чтобы карточка показывала «опубликована / черновик / нет»;
 *   3. черновик и публикация схемы проходят по HTTP и видны в списке;
 *   4. смена `schema_version_id` у сеанса пересобирает инвентарь;
 *   5. при продажах и активных корзинах смена схемы — 409, а сеанс остаётся
 *      на прежней версии (иначе места и билеты разъезжаются).
 */
final class VenueSchemaFlowHttpTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;
    use StaffAuth;

    /** @return array<string, mixed> */
    private function schemaPayload(int $priceMinor, int $seats = 2): array
    {
        $list = [];
        for ($i = 1; $i <= $seats; $i++) {
            $list[] = [
                'id' => 'seat-' . $i,
                'row' => 1,
                'number' => $i,
                'x' => 20 + ($i - 1) * 22,
                'y' => 20,
            ];
        }

        return [
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [[
                'name' => 'Партер',
                'priceMinor' => $priceMinor,
                'seats' => $list,
            ]],
        ];
    }

    /** Черновик + публикация через HTTP. Возвращает id опубликованной версии. */
    private function publishSchemaViaHttp(string $hallPublicId, array $payload): int
    {
        $draft = $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/draft", [
            'payload' => $payload,
        ])->assertSuccessful();

        $versionId = (int) $draft->json('data.id');
        self::assertGreaterThan(0, $versionId, 'Черновик схемы должен вернуть id версии');

        $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/{$versionId}/publish")
            ->assertSuccessful();

        return $versionId;
    }

    private function seedOrganization(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_creating_venue_creates_default_hall(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $response = $this->postJson('/api/v1/venues', [
            'name' => 'ДК «Маяк»',
            'city' => 'Сургут',
        ])->assertCreated();

        // Техническая строка схемы больше не называется «Основной зал»:
        // пользователь работает с площадкой, а не с вложенным залом.
        self::assertSame('ДК «Маяк»', $response->json('hall.name'));

        $hallPublicId = $response->json('hall.public_id');
        self::assertIsString($hallPublicId);

        $venueId = (int) DB::table('venues')->where('public_id', $response->json('data.public_id'))->value('id');

        $this->assertDatabaseHas('halls', [
            'venue_id' => $venueId,
            'name' => 'ДК «Маяк»',
            'organization_id' => $organizationId,
        ]);

        // Ответ уже содержит состояние схемы зала: карточке есть что показать.
        self::assertArrayHasKey('halls', $response->json('data'));
    }

    public function test_creating_venue_accepts_custom_hall_name(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $this->postJson('/api/v1/venues', [
            'name' => 'Филармония',
            'hall_name' => 'Камерный зал',
        ])
            ->assertCreated()
            ->assertJsonPath('hall.name', 'Камерный зал');
    }

    public function test_venue_list_reports_published_schema_of_hall(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $created = $this->postJson('/api/v1/venues', ['name' => 'Клуб «Геолог»'])->assertCreated();
        $hallPublicId = (string) $created->json('hall.public_id');

        // До схемы — карточка показывает «нет схемы».
        $before = $this->getJson('/api/v1/venues?per_page=100')->assertOk();
        self::assertNull($before->json('data.data.0.halls.0.current_schema_version'));

        $this->publishSchemaViaHttp($hallPublicId, $this->schemaPayload(850000));

        $after = $this->getJson('/api/v1/venues?per_page=100')->assertOk();
        self::assertSame(1, $after->json('data.data.0.halls.0.current_schema_version.version'));
        self::assertSame('published', $after->json('data.data.0.halls.0.current_schema_version.status'));
    }

    /**
     * Схема принадлежит площадке: конструктор работает с publicId площадки,
     * а не с technical-строкой «зала», которую пользователь не видит.
     */
    public function test_schema_can_be_managed_by_venue_public_id(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $venue = $this->postJson('/api/v1/venues', ['name' => 'Филармония'])->assertCreated();
        $venuePublicId = (string) $venue->json('data.public_id');

        $draft = $this->postJson("/api/v1/venues/{$venuePublicId}/schema-versions/draft", [
            'payload' => $this->schemaPayload(500000),
        ])->assertSuccessful();

        $versionId = (int) $draft->json('data.id');
        self::assertGreaterThan(0, $versionId);
        self::assertSame((string) $venue->json('data.id'), (string) $draft->json('data.venue_id'));

        $list = $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions")->assertOk();
        self::assertCount(1, $list->json('data'));

        $this->postJson("/api/v1/venues/{$venuePublicId}/schema-versions/{$versionId}/publish", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'published');
    }

    /**
     * Публичный список версий площадки не должен падать 500 для гостя:
     * модель версий tenant-scoped, поэтому чтение идёт в OrganizationContext::withoutScope.
     */
    public function test_version_list_is_light_and_full_schema_is_loaded_on_demand(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $venue = $this->postJson('/api/v1/venues', ['name' => 'Лёгкий список версий'])->assertCreated();
        $venuePublicId = (string) $venue->json('data.public_id');
        $hallPublicId = (string) $venue->json('hall.public_id');
        $versionId = $this->publishSchemaViaHttp($hallPublicId, $this->schemaPayload(500000));

        $list = $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions")->assertOk();
        $first = $list->json('data.0');
        self::assertArrayNotHasKey('schema', $first, 'список не должен тащить схему до 2 МБ на версию');
        self::assertSame($versionId, (int) $first['id']);

        $detail = $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions/{$versionId}")->assertOk();
        self::assertNotEmpty($detail->json('data.schema.sectors'));

        // Черновик виден сотруднику, но не гостю витрины.
        $draft = $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/draft", [
            'payload' => $this->schemaPayload(600000),
        ])->assertSuccessful();
        $draftId = (int) $draft->json('data.id');
        $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions/{$draftId}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions/{$versionId}")->assertOk();
        $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions/{$draftId}")->assertNotFound();
    }

    public function test_a_guest_can_list_schema_versions_of_a_venue(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $venue = $this->postJson('/api/v1/venues', ['name' => 'Гостьовая площадка'])->assertCreated();
        $venuePublicId = (string) $venue->json('data.public_id');
        $this->postJson("/api/v1/venues/{$venuePublicId}/schema-versions/draft", [
            'payload' => $this->schemaPayload(500000),
        ])->assertSuccessful();

        // Сотрудник своей организации видит черновик.
        $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Аудит 2026-11-25 (I-6): endpoint объявлен публичным, поэтому гость
        // не должен перечислять черновики — раньше он получал тот же список.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/venues/{$venuePublicId}/schema-versions")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_schema_draft_and_publish_over_http(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $created = $this->postJson('/api/v1/venues', ['name' => 'Театр кукол'])->assertCreated();
        $hallPublicId = (string) $created->json('hall.public_id');

        $versionId = $this->publishSchemaViaHttp($hallPublicId, $this->schemaPayload(500000));

        $versions = $this->getJson("/api/v1/halls/{$hallPublicId}/schema-versions")->assertOk();
        $published = collect($versions->json('data'))
            ->firstWhere(fn ($v) => (int) ($v['id'] ?? 0) === $versionId);

        self::assertNotNull($published, 'Опубликованная версия должна быть в списке версий');
        self::assertSame('published', $published['status']);
        self::assertSame(1, (int) $published['version']);
    }

    public function test_switching_session_schema_regenerates_inventory(): void
    {
        [$organizationId, , $sessionId, $oldItemId] = $this->seedSellableSeat(5);
        $hallPublicId = (string) DB::table('halls')->where('id', DB::table('sessions')->where('id', $sessionId)->value('hall_id'))->value('public_id');

        $this->actingAsStaff($organizationId);

        // Новая опубликованная версия: два места в партере.
        $newVersionId = $this->publishSchemaViaHttp($hallPublicId, $this->schemaPayload(700000, 2));

        $this->patchJson("/api/v1/sessions/{$sessionId}", [
            'schema_version_id' => $newVersionId,
        ])
            ->assertOk()
            ->assertJsonPath('data.schema_version_id', $newVersionId);

        // Старый стоячий инвентарь снят, на его месте — места новой схемы.
        $this->assertDatabaseMissing('inventory_items', ['id' => $oldItemId], 'mysql');

        $items = DB::table('inventory_items')->where('session_id', $sessionId)->get();
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame('seat', $item->type);
            self::assertNotNull($item->seat_id);
        }
    }

    public function test_switching_session_schema_with_sales_is_rejected(): void
    {
        [$organizationId, $eventId, $sessionId, $inventoryItemId] = $this->seedSellableSeat(5);
        $session = DB::table('sessions')->where('id', $sessionId)->first();
        $hallPublicId = (string) DB::table('halls')->where('id', $session->hall_id)->value('public_id');

        $this->actingAsStaff($organizationId);

        $newVersionId = $this->publishSchemaViaHttp($hallPublicId, $this->schemaPayload(700000, 2));

        // Проданное место: позиция заказа (источник правды проверки продаж —
        // тикет мог быть ещё не выписан).
        $now = now()->toDateTimeString();
        $orderId = (int) DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'organization_id' => $organizationId,
            'subtotal_amount' => 50000,
            'total_amount' => 50000,
            'customer_email' => 'buyer@example.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'organization_id' => $organizationId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
            'unit_price' => 50000,
            'total_amount' => 50000,
            'event_title_snapshot' => 'Тестовое событие',
            'created_at' => $now,
        ]);

        $this->patchJson("/api/v1/sessions/{$sessionId}", [
            'schema_version_id' => $newVersionId,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_SCHEMA_HAS_SALES');

        // Сеанс остался на прежней версии, инвентарь не тронут: иначе билеты
        // ссылались бы на удалённые места.
        self::assertSame(
            (int) $session->schema_version_id,
            (int) DB::table('sessions')->where('id', $sessionId)->value('schema_version_id'),
        );
        $this->assertDatabaseHas('inventory_items', ['id' => $inventoryItemId], 'mysql');
    }

    public function test_switching_session_schema_with_active_hold_is_rejected(): void
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(5);
        $session = DB::table('sessions')->where('id', $sessionId)->first();
        $hallPublicId = (string) DB::table('halls')->where('id', $session->hall_id)->value('public_id');

        $this->actingAsStaff($organizationId);

        $newVersionId = $this->publishSchemaViaHttp($hallPublicId, $this->schemaPayload(700000, 2));

        $now = now()->toDateTimeString();
        $cartId = (int) DB::table('carts')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('seat_holds')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'inventory_item_id' => $inventoryItemId,
            'session_id' => $sessionId,
            'cart_id' => $cartId,
            'quantity' => 1,
            'expires_at' => now()->addMinutes(15)->toDateTimeString(),
            'created_at' => $now,
        ]);

        $this->patchJson("/api/v1/sessions/{$sessionId}", [
            'schema_version_id' => $newVersionId,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_SCHEMA_HELD');

        self::assertSame(
            (int) $session->schema_version_id,
            (int) DB::table('sessions')->where('id', $sessionId)->value('schema_version_id'),
        );
    }

    /**
     * Сквозной сценарий «мероприятие → два концерта → разные цены»
     * (аудит ценовых групп 2026-11-18).
     *
     * Схема задаёт три ценовые группы: сектор 5000 ₽, ряд 7000 ₽ и одно
     * VIP-место 9000 ₽. Первый сеанс переоценивает РЯД до 12000 ₽ только для
     * себя; второй сеанс того же зала должен получить базовые 7000/9000 ₽,
     * а витрина (`GET /inventory`) — показать цены именно первого концерта.
     */
    public function test_each_concert_gets_its_own_prices_from_the_same_schema(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $venue = $this->postJson('/api/v1/venues', ['name' => 'Концертный зал'])->assertCreated();
        $venueId = (int) $venue->json('data.id');
        $hallPublicId = (string) $venue->json('hall.public_id');
        $hallId = (int) DB::table('halls')->where('public_id', $hallPublicId)->value('id');

        $payload = $this->schemaPayload(500000, 2);
        $payload['sectors'][0]['rowPrices'] = [1 => 700000];
        $payload['sectors'][0]['seats'][0]['priceMinor'] = 900000;
        $this->publishSchemaViaHttp($hallPublicId, $payload);

        $event = $this->postJson('/api/v1/events', [
            'slug' => 'concert-price-flow-' . Str::lower(Str::random(8)),
            'title' => 'Концерт с разными ценами',
        ])->assertCreated();
        $eventId = (int) $event->json('data.id');

        $sessionId = (int) $this->postJson('/api/v1/sessions', [
            'event_id' => $eventId,
            'venue_id' => $hallId,
            'hall_id' => $hallId,
            'starts_at' => '2026-12-01 19:00:00',
        ])->assertCreated()->json('data.id');

        $pricesOf = static fn (int $id): array => DB::table('inventory_items')
            ->where('session_id', $id)
            ->join('seats', 'seats.id', '=', 'inventory_items.seat_id')
            ->orderBy('seats.number')
            ->pluck('inventory_items.price_amount')
            ->map(static fn ($p) => (int) $p)
            ->all();

        self::assertSame([900000, 700000], $pricesOf($sessionId), 'ценовые группы из схемы не доехали в инвентарь');

        $rowId = (int) DB::table('hall_rows')
            ->join('sectors', 'sectors.id', '=', 'hall_rows.sector_id')
            ->where('sectors.schema_version_id', DB::table('sessions')->where('id', $sessionId)->value('schema_version_id'))
            ->value('hall_rows.id');

        $this->patchJson("/api/v1/inventory/sessions/{$sessionId}/prices", [
            'rows' => [['row_id' => $rowId, 'price_amount' => 1200000]],
            'session_only' => true,
        ])->assertOk()
            ->assertJsonPath('data.session_only', true)
            ->assertJsonPath('data.items_kept_individual', 1);

        self::assertSame([900000, 1200000], $pricesOf($sessionId));

        // Витрина читает тот же инвентарь: покупатель видит цены концерта.
        $storefront = $this->getJson("/api/v1/inventory?session_id={$sessionId}")->assertOk();
        self::assertSame([900000, 1200000], collect($storefront->json('data.data'))
            ->pluck('price_amount')->map(static fn ($p) => (int) $p)->all());

        // Второй концерт в том же зале: базовая сетка схемы, без разовой лесенницы.
        $secondId = (int) $this->postJson('/api/v1/sessions', [
            'event_id' => $eventId,
            'venue_id' => $hallId,
            'hall_id' => $hallId,
            'starts_at' => '2026-12-02 19:00:00',
        ])->assertCreated()->json('data.id');

        self::assertSame([900000, 700000], $pricesOf($secondId), 'цены первого концерта протекли во второй');
    }
}
