<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Цены рядов конкретного сеанса: `UpdateRowPrices` и флаг `session_only`.
 *
 * Повод — жалоба «цена концерта протекает в будущие сеансы зала».
 * `InventoryService::updateRowPrices()` перезаписывал `hall_rows.price_amount`,
 * который `generateFromSchema()` берёт как источник истины: следующая дата в том
 * же зале наследовала разовую ценовую лесенницу предыдущего концерта. Флаг
 * `session_only` отвязывает инвентарь сеанса от сетки зала.
 *
 * Тесты смотрят на СТРОКИ В БД, а не только на код ответа: «200» сам по себе
 * не говорит, осталась ли базовая сетка зала нетронутой — а именно в этом
 * разница между режимами.
 */
final class SessionRowPricesTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $eventId;

    private int $venueId;

    private int $hallId;

    private int $schemaVersionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin($this->organizationId);

        $this->eventId = $this->seedEvent($this->organizationId);
        $this->venueId = $this->seedVenue($this->organizationId, 'КЗ «Вавилон»');
        $this->hallId = $this->seedHall($this->venueId, 'Основной зал');
        $this->schemaVersionId = $this->seedSchema($this->hallId, $this->seatedSchema());
    }

    // ── Дефолтный режим: сеанс + сетка зала ─────────────────────────────────

    public function test_default_mode_updates_both_the_session_and_the_hall_grid(): void
    {
        $sessionId = $this->createSession($this->hallId, '2026-11-20 19:00');
        $rowId = $this->rowIdOf($sessionId, number: '1');

        $result = app(InventoryService::class)->updateRowPrices($sessionId, [
            ['row_id' => $rowId, 'price_amount' => 600_000],
        ]);

        $this->assertFalse($result['session_only'], 'по умолчанию флаг выключен — это старый режим');
        $this->assertSame(1, $result['rows_updated']);

        $this->assertSame(
            600_000,
            (int) DB::table('hall_rows')->where('id', $rowId)->value('price_amount'),
            'в дефолтном режиме сетка зала тоже переоценивается — так ведут себя уже существующие клиенты'
        );

        $this->assertSame(
            600_000,
            (int) DB::table('inventory_items')->where('session_id', $sessionId)->where('seat_id', $this->seatIdOf($rowId))->value('price_amount'),
        );
    }

    // ── session_only: сеанс без сетки зала ──────────────────────────────────

    public function test_session_only_mode_leaves_the_hall_grid_untouched(): void
    {
        $sessionId = $this->createSession($this->hallId, '2026-11-20 19:00');
        $rowId = $this->rowIdOf($sessionId, number: '1');
        $schemaGridBefore = $this->gridPriceSum();

        $result = app(InventoryService::class)->updateRowPrices($sessionId, [
            ['row_id' => $rowId, 'price_amount' => 600_000],
        ], sessionOnly: true);

        $this->assertTrue($result['session_only']);
        $this->assertSame(1, $result['rows_updated']);
        // Ряд 1 в фикстуре — два места, значит и позиций переоценено две.
        $this->assertSame(2, $result['items_updated']);

        // Сетка зала не тронута — главное свойство режима.
        $this->assertSame($schemaGridBefore, $this->gridPriceSum(), 'session_only перезаписал сетку зала');

        // …а инвентарь сеанса — переоценён.
        $this->assertSame(
            600_000,
            (int) DB::table('inventory_items')->where('session_id', $sessionId)->where('seat_id', $this->seatIdOf($rowId))->value('price_amount'),
        );
    }

    /**
     * Смысл флага целиком: разовая лесенка не протекает в соседнюю дату.
     *
     * Ровно тот сценарий, ради которого его добавили — «схема у нас общая,
     * цены у каждого концерта свои».
     */
    public function test_a_later_session_of_the_same_hall_keeps_the_schema_price(): void
    {
        $first = $this->createSession($this->hallId, '2026-11-20 19:00');
        $rowId = $this->rowIdOf($first, number: '1');

        app(InventoryService::class)->updateRowPrices($first, [
            ['row_id' => $rowId, 'price_amount' => 600_000],
        ], sessionOnly: true);

        // Следующая дата в том же зале: новый инвентарь из той же версии схемы.
        $second = $this->createSession($this->hallId, '2026-11-21 19:00');

        $this->assertSame(
            100_000,
            (int) DB::table('inventory_items')
                ->where('session_id', $second)
                ->where('seat_id', $this->seatIdOf($rowId))
                ->value('price_amount'),
            'второй сеанс унаследовал цену первого вместо базовой схемы'
        );

        // Сетка зала осталась базовой: это и есть «схема общая, цены свои».
        $this->assertSame(
            100_000,
            (int) DB::table('hall_rows')->where('id', $rowId)->value('price_amount'),
            'session_only всё же перезаписал сетку зала'
        );
    }

    // ── Договор покупки: проданное место не переоценивается ────────────────

    public function test_a_partially_sold_row_keeps_the_price_of_the_seats_already_sold(): void
    {
        $sessionId = $this->createSession($this->hallId, '2026-11-20 19:00');
        $rowId = $this->rowIdOf($sessionId, number: '1');
        $seatId = $this->seatIdOf($rowId);

        // Одно место продано: именно такая строка и есть «контракт покупки».
        DB::table('inventory_items')
            ->where('session_id', $sessionId)
            ->where('seat_id', $seatId)
            ->update(['status' => 'sold', 'available_quantity' => 0]);

        app(InventoryService::class)->updateRowPrices($sessionId, [
            ['row_id' => $rowId, 'price_amount' => 600_000],
        ], sessionOnly: true);

        $this->assertSame(
            100_000,
            (int) DB::table('inventory_items')->where('session_id', $sessionId)->where('seat_id', $seatId)->value('price_amount'),
            'проданное место переоценилось задним числом'
        );

        // Свободные места того же ряда — новая цена.
        $this->assertSame(
            600_000,
            (int) DB::table('inventory_items')
                ->where('session_id', $sessionId)
                ->where('seat_id', '!=', $seatId)
                ->whereNotNull('seat_id')
                ->whereColumn('available_quantity', 'capacity')
                ->value('price_amount'),
        );
    }

    // ── Стоячая зона: тот же режим ──────────────────────────────────────────

    public function test_session_only_scopes_the_standing_zone_price_too(): void
    {
        $hallId = $this->seedHall($this->venueId, 'Зал с танцполом');
        $this->seedSchema($hallId, $this->standingSchema());
        $sessionId = $this->createSession($hallId, '2026-11-20 19:00');
        // Зону ищем через инвентарь сеанса — так же, как это делает сервис.
        $zoneId = (int) DB::table('inventory_items')
            ->where('session_id', $sessionId)
            ->whereNotNull('standing_zone_id')
            ->value('standing_zone_id');

        $zoneGridBefore = (int) DB::table('standing_zones')->where('id', $zoneId)->value('price_amount');

        app(InventoryService::class)->updateRowPrices($sessionId, [
            ['standing_zone_id' => $zoneId, 'price_amount' => 150_000],
        ], sessionOnly: true);

        $this->assertSame($zoneGridBefore, (int) DB::table('standing_zones')->where('id', $zoneId)->value('price_amount'),
            'session_only перезаписал цену зоны в сетке зала');

        $this->assertSame(
            150_000,
            (int) DB::table('inventory_items')->where('session_id', $sessionId)->where('standing_zone_id', $zoneId)->value('price_amount'),
        );
    }

    // ── HTTP-слой: флаг проходит и виден в ответе ───────────────────────────

    public function test_session_only_is_accepted_over_http_and_reported_in_the_response(): void
    {
        $sessionId = $this->createSession($this->hallId, '2026-11-20 19:00');
        $rowId = $this->rowIdOf($sessionId, number: '2');
        $gridBefore = $this->gridPriceSum();

        $this->patchJson("/api/v1/inventory/sessions/{$sessionId}/prices", [
            'rows' => [['row_id' => $rowId, 'price_amount' => 250_000]],
            'session_only' => true,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.session_only', true)
            ->assertJsonPath('data.rows_updated', 1);

        $this->assertSame($gridBefore, $this->gridPriceSum(), 'HTTP-вызов с session_only перезаписал сетку зала');

        $this->patchJson("/api/v1/inventory/sessions/{$sessionId}/prices", [
            'rows' => [['row_id' => $rowId, 'price_amount' => 250_000]],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.session_only', false);

        // Без флага сетка зала меняется — прежний контракт для текущей админки.
        $this->assertSame($gridBefore + 150_000, $this->gridPriceSum());
    }

    // ── Кто имеет право: список ролей живёт в одном месте ───────────────────

    /**
     * `UpdateRowPricesRequest::authorize()` и middleware `admin` задают ОДИН
     * вопрос одному владельцу (`StaffRole`). Пока ответ на него расходился,
     * смысл defense-in-depth терялся: FormRequest держал свой список ролей,
     * и добавление роли в `StaffRole::ROLES` не доезжало до него.
     *
     * Тест смотрит на оба конца: пользователь без роли не пишет (403), а
     * `manager` — пишет (200). Второй assert не про вежливость: он ловит
     * rewiring, который сузил набор ролей до одного `admin`.
     */
    public function test_the_prices_endpoint_refuses_a_plain_user_and_admits_a_manager(): void
    {
        $sessionId = $this->createSession($this->hallId, '2026-11-20 19:00');
        $rowId = $this->rowIdOf($sessionId, number: '1');
        $seatId = $this->seatIdOf($rowId);
        $payload = ['rows' => [['row_id' => $rowId, 'price_amount' => 250_000]]];

        $priceOf = fn (): int => (int) DB::table('inventory_items')
            ->where('session_id', $sessionId)
            ->where('seat_id', $seatId)
            ->value('price_amount');

        $before = $priceOf();
        $this->assertSame(100_000, $before, 'фикстура: цена ряда в сетке зала изменилась');

        $this->actingAs($this->actingAsUserWithRole('customer'), 'api');
        $this->patchJson("/api/v1/inventory/sessions/{$sessionId}/prices", $payload)
            ->assertStatus(403);

        $this->assertSame($before, $priceOf(), 'пользователь без роли прошёл authorize() и цена изменилась');

        $this->actingAs($this->actingAsUserWithRole('manager'), 'api');
        $this->patchJson("/api/v1/inventory/sessions/{$sessionId}/prices", $payload)
            ->assertStatus(200);

        $this->assertSame(
            250_000,
            $priceOf(),
            'manager — сотрудник (StaffRole::ROLES), и после rewiring он потерял доступ',
        );
    }

    // ── Схемы и фикстуры ───────────────────────────────────────────────────

    /**
     * rows-формат (как отдаёт импортёр Афиши). У рядов РАЗНЫЕ цены, чтобы
     * проверить, что переоценивается конкретный ряд, а не весь сектор.
     *
     * @return array<string, mixed>
     */
    private function seatedSchema(): array
    {
        return [
            'sectors' => [[
                'name' => 'Партер',
                'code' => 'P',
                'type' => 'seated',
                'rows' => [
                    ['number' => '1', 'label' => 'Ряд 1', 'price_amount' => 100_000, 'seats' => [
                        ['number' => '1', 'label' => 'Ряд 1 Место 1', 'type' => 'standard'],
                        ['number' => '2', 'label' => 'Ряд 1 Место 2', 'type' => 'standard'],
                    ]],
                    ['number' => '2', 'label' => 'Ряд 2', 'price_amount' => 100_000, 'seats' => [
                        ['number' => '1', 'label' => 'Ряд 2 Место 1', 'type' => 'standard'],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * Сектор `type=standing`: `generateFromSchema` сворачивает его места в
     * вместимость и создаёт `standing_zones` + одну позицию инвентаря.
     *
     * @return array<string, mixed>
     */
    private function standingSchema(): array
    {
        return [
            'sectors' => [[
                'name' => 'Танцпол',
                'code' => 'D',
                'type' => 'standing',
                'rows' => [[
                    'number' => '1',
                    'label' => 'Танцпол',
                    'price_amount' => 100_000,
                    'seats' => [
                        ['number' => '1', 'label' => 'Танцпол 1', 'type' => 'standing'],
                        ['number' => '2', 'label' => 'Танцпол 2', 'type' => 'standing'],
                    ],
                ]],
            ]],
        ];
    }

    private function createSession(int $hallId, string $startsAt): int
    {
        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'hall_id' => $hallId,
            'starts_at' => $startsAt,
            'status' => 'scheduled',
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** `hall_rows.id` ряда сектора «Партер» по номеру ряда. */
    private function rowIdOf(int $sessionId, string $number): int
    {
        $rowId = (int) DB::table('inventory_items')
            ->where('session_id', $sessionId)
            ->whereNotNull('seat_id')
            ->join('seats', 'seats.id', '=', 'inventory_items.seat_id')
            ->join('hall_rows', 'hall_rows.id', '=', 'seats.row_id')
            ->where('hall_rows.number', $number)
            ->orderBy('inventory_items.id')
            ->value('hall_rows.id');

        $this->assertGreaterThan(0, $rowId, "ряд {$number} не найден в инвентаре сеанса");

        return $rowId;
    }

    private function seatIdOf(int $rowId): int
    {
        return (int) DB::table('seats')->where('row_id', $rowId)->orderBy('id')->value('id');
    }

    private function gridPriceSum(): int
    {
        return (int) DB::table('hall_rows')
            ->join('sectors', 'sectors.id', '=', 'hall_rows.sector_id')
            ->where('sectors.schema_version_id', $this->schemaVersionId)
            ->sum('hall_rows.price_amount');
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

    private function seedEvent(int $organizationId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Тестовый концерт',
            'slug' => 'test-concert-' . Str::random(6),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVenue(int $organizationId, string $name): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => 'venue-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedHall(int $venueId, string $name): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => $name,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function seedSchema(int $hallId, array $schema, string $status = 'published'): int
    {
        $version = HallSchemaVersion::create([
            'hall_id' => $hallId,
            'version' => 1,
            'revision' => 1,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'schema_json' => $schema,
        ]);

        return (int) $version->id;
    }

    private function actingAsAdmin(int $organizationId): User
    {
        $user = $this->actingAsUserWithRole('admin', $organizationId);
        $this->actingAs($user, 'api');

        return $user;
    }

    /**
     * Пользователь ровно с одной ролью. Роль создаётся при первом
     * обращении — в схеме нет сида ролей, и `manager` до сих пор не
     * требовался ни одному тесту.
     */
    private function actingAsUserWithRole(string $slug, ?int $organizationId = null): User
    {
        $now = now();
        $organizationId ??= $this->organizationId;

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', $slug)->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => $slug,
                'slug' => $slug,
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($userId);
    }
}
