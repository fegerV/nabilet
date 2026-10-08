<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Второй сеанс в том же зале.
 *
 * Повод — жалоба «на странице мероприятия нельзя выбрать площадку и дату».
 * Площадка и дата живут не в `events`, а в `sessions` (`venue_id`, `hall_id`,
 * `starts_at` — все NOT NULL), поэтому администратору нужен путь «создать сеанс
 * из карточки мероприятия». Пройти по нему было нельзя: второй сеанс в зале, где
 * уже есть сеанс с инвентарём, отвечал 500.
 *
 * Причина — в `InventoryService::generateFromSchema()`. Геометрия зала
 * (`sectors` → `hall_rows` → `seats`) принадлежит ВЕРСИИ СХЕМЫ, а не сеансу: в
 * этой цепочке нет `session_id`. Метод же удалял её и создавал заново при каждом
 * сеансе, а `inventory_items.seat_id` держит `seats` через ON DELETE RESTRICT —
 * `delete from seats` упирался в FK (SQLSTATE 23000 / 1451).
 *
 * Тесты проверяют поведение по СТРОКАМ В БД, а не по коду ответа: «201» сам по
 * себе ничего не говорит о том, уцелел ли инвентарь первого сеанса.
 *
 * ПРО ОГРАНИЧЕНИЯ СХЕМЫ, которые определили форму этих тестов:
 *  - `uq_schema_one_published_per_hall` / `uq_schema_one_draft_per_hall` — у зала
 *    не может быть больше одной версии в каждом из этих статусов;
 *  - `trg_schema_version_lifecycle_guard` — данные ОПУБЛИКОВАННОЙ версии
 *    (`schema_json`, `width`, `height`, `published_at`, `revision`) менять
 *    нельзя (spec §20/§98). Значит, «геометрия разошлась со схемой» достижимо
 *    только для ЧЕРНОВИКА, и проверять это надо на отдельном зале.
 */
final class SessionGeometryReuseTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $eventId;

    private int $venueId;

    private int $otherVenueId;

    private int $hallId;

    private int $schemaVersionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin($this->organizationId);

        $this->eventId = $this->seedEvent($this->organizationId);
        $this->venueId = $this->seedVenue($this->organizationId, 'КЗ «Тестовый»');
        $this->otherVenueId = $this->seedVenue($this->organizationId, 'Тест-холл Проверка');
        $this->hallId = $this->seedHall($this->venueId, 'Большой зал');
        $this->schemaVersionId = $this->seedSchema($this->hallId, $this->seatedSchema(2, 2));
    }

    // ── 1. Главная регрессия: второй сеанс в том же зале ────────────────────

    public function test_a_second_session_on_the_same_hall_is_created_and_reuses_the_geometry(): void
    {
        $first = $this->createSession($this->hallId, '2026-11-20 19:00');
        $firstSeatIds = $this->seatIdsOfSession($first);

        $this->assertCount(4, $firstSeatIds, 'первый сеанс должен получить позицию на каждое место');

        // Вторая дата в том же зале. До исправления здесь был 500.
        $second = $this->createSession($this->hallId, '2026-11-21 19:00');

        $this->assertCount(4, $this->seatIdsOfSession($second), 'второй сеанс не получил инвентарь');

        $this->assertSame(
            $firstSeatIds,
            $this->seatIdsOfSession($first),
            'геометрия была пересоздана: первый сеанс указывает уже на другие места'
        );
    }

    public function test_sold_inventory_of_the_first_session_survives_the_second_session(): void
    {
        $first = $this->createSession($this->hallId, '2026-11-20 19:00');

        // Один билет продан. Именно такая строка делает удаление мест
        // невозможным: FK смотрит на существование места, а не на его статус.
        $soldItemId = (int) DB::table('inventory_items')
            ->where('session_id', $first)
            ->orderBy('id')
            ->value('id');

        DB::table('inventory_items')->where('id', $soldItemId)->update([
            'status' => 'sold',
            'available_quantity' => 0,
        ]);

        $this->createSession($this->hallId, '2026-11-21 19:00');

        $this->assertSame(
            'sold',
            (string) DB::table('inventory_items')->where('id', $soldItemId)->value('status'),
            'проданная позиция первого сеанса потерялась'
        );

        $this->assertSame(
            0,
            (int) DB::table('inventory_items')
                ->leftJoin('seats', 'seats.id', '=', 'inventory_items.seat_id')
                ->whereNotNull('inventory_items.seat_id')
                ->whereNull('seats.id')
                ->count(),
            'в `inventory_items` остались ссылки на удалённые места'
        );
    }

    public function test_the_geometry_is_not_duplicated_by_a_second_session(): void
    {
        $this->createSession($this->hallId, '2026-11-20 19:00');
        $seatsAfterFirst = $this->seatCountOfSchemaVersion($this->schemaVersionId);

        $this->createSession($this->hallId, '2026-11-21 19:00');

        $this->assertSame(
            $seatsAfterFirst,
            $this->seatCountOfSchemaVersion($this->schemaVersionId),
            'второй сеанс создал вторую копию геометрии вместо переиспользования'
        );
    }

    // ── 2. Площадка и зал должны быть согласованы ───────────────────────────

    public function test_a_hall_from_another_venue_is_rejected(): void
    {
        $otherHallId = $this->seedHall($this->otherVenueId, 'Большой зал другого театра');
        $this->seedSchema($otherHallId, $this->seatedSchema(1, 2));

        $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'venue_id' => $this->venueId,
            'hall_id' => $otherHallId,
            'starts_at' => '2026-11-20 19:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['hall_id']]]]);

        $this->assertSame(
            0,
            (int) DB::table('sessions')->where('hall_id', $otherHallId)->count(),
            'несогласованная пара «площадка + зал» всё же записалась'
        );
    }

    public function test_a_hall_from_the_same_venue_is_accepted(): void
    {
        $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'venue_id' => $this->venueId,
            'hall_id' => $this->hallId,
            'starts_at' => '2026-11-20 19:00',
        ])->assertStatus(201);

        $row = DB::table('sessions')->where('hall_id', $this->hallId)->first();

        $this->assertNotNull($row);
        $this->assertSame($this->venueId, (int) $row->venue_id);
    }

    // ── 3. Зал без опубликованной схемы ─────────────────────────────────────

    public function test_a_hall_without_a_published_schema_is_rejected_with_a_field_error(): void
    {
        $bareHallId = $this->seedHall($this->venueId, 'Зал без схемы');

        // Черновик: опубликованной версии у зала нет, а `sessions.schema_version_id`
        // — NOT NULL. Раньше вывод оставлял поле пустым, INSERT падал на NOT NULL,
        // и клиент получал 500 «Something went wrong» вместо объяснения.
        $this->seedSchema($bareHallId, $this->seatedSchema(1, 2), 'draft');

        $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'hall_id' => $bareHallId,
            'starts_at' => '2026-11-20 19:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['hall_id']]]]);

        $this->assertSame(0, (int) DB::table('sessions')->where('hall_id', $bareHallId)->count());
    }

    // ── 4. Геометрия разошлась со схемой ────────────────────────────────────

    public function test_stale_geometry_sold_by_another_session_is_refused_instead_of_destroyed(): void
    {
        [$hallId, $draftId] = $this->seedDraftHall($this->seatedSchema(2, 2));

        $service = app(InventoryService::class);
        $first = $this->makeSessionModel($hallId, $draftId);

        $this->assertSame(4, $service->generateFromSchema($this->versionModel($draftId), $first));

        // Схему дополнили местами. Инвентарь первого сеанса держит прежние места
        // через ON DELETE RESTRICT, поэтому пересборка обязана ОТКАЗАТЬ, а не
        // снести их: молча отдать покупателю схему, которой уже нет, хуже, чем
        // отказать администратору с объяснением.
        $this->mutateSchema($draftId, $this->seatedSchema(2, 3));

        try {
            $service->generateFromSchema($this->versionModel($draftId), $this->makeSessionModel($hallId, $draftId));
            $this->fail('ожидался ConflictError: геометрию уже продаёт другой сеанс');
        } catch (ConflictError $e) {
            // Пин на тип ошибки: 409 и собственный код, а не VALIDATION_ERROR.
            // Запрос корректен — отказ вызван состоянием данных.
            $this->assertSame('SCHEMA_GEOMETRY_IN_USE', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(
            4,
            $this->seatCountOfSchemaVersion($draftId),
            'отказ всё же пересобрал геометрию, которую продаёт другой сеанс'
        );
        $this->assertCount(4, $this->seatIdsOfSession((int) $first->id), 'инвентарь первого сеанса потерялся');
    }

    public function test_stale_geometry_that_nobody_sells_is_rebuilt(): void
    {
        [$hallId, $draftId] = $this->seedDraftHall($this->seatedSchema(0, 0));

        $service = app(InventoryService::class);

        // Схема без мест: геометрия материализуется (сектор), но инвентаря нет —
        // ровно тот случай, когда пересборка безопасна.
        $session = $this->makeSessionModel($hallId, $draftId);
        $this->assertSame(0, $service->generateFromSchema($this->versionModel($draftId), $session));

        // Схему дополнили местами. Ссылок на геометрию нет, поэтому её надо
        // пересобрать, а не считать актуальной.
        $this->mutateSchema($draftId, $this->seatedSchema(1, 2));

        $this->assertSame(
            2,
            $service->generateFromSchema($this->versionModel($draftId), $session),
            'устаревшая геометрия без продаж должна пересобираться'
        );

        $this->assertSame(2, $this->seatCountOfSchemaVersion($draftId));
    }

    // ── Помощники ───────────────────────────────────────────────────────────

    /**
     * Схема зала в rows-формате (как отдаёт импортёр Афиши).
     *
     * `toInventoryFormat()` пропускает готовый `rows` насквозь, а канвасный
     * формат пришлось бы описывать координатами — для проверки переиспользования
     * геометрии это лишний шум.
     *
     * @return array<string, mixed>
     */
    private function seatedSchema(int $rowsCount, int $seatsPerRow): array
    {
        $rows = [];

        for ($r = 1; $r <= $rowsCount; $r++) {
            $seats = [];

            for ($s = 1; $s <= $seatsPerRow; $s++) {
                $seats[] = [
                    'number' => (string) $s,
                    'label' => "Ряд {$r} Место {$s}",
                    'type' => 'standard',
                ];
            }

            $rows[] = [
                'number' => (string) $r,
                'label' => "Ряд {$r}",
                'price_amount' => 100_000,
                'seats' => $seats,
            ];
        }

        return [
            'sectors' => [[
                'name' => 'Партер',
                'code' => 'P',
                'type' => 'seated',
                'rows' => $rows,
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

    /** @return list<int> */
    private function seatIdsOfSession(int $sessionId): array
    {
        return DB::table('inventory_items')
            ->where('session_id', $sessionId)
            ->whereNotNull('seat_id')
            ->orderBy('seat_id')
            ->pluck('seat_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function seatCountOfSchemaVersion(int $schemaVersionId): int
    {
        return (int) DB::table('seats')
            ->join('hall_rows', 'hall_rows.id', '=', 'seats.row_id')
            ->join('sectors', 'sectors.id', '=', 'hall_rows.sector_id')
            ->where('sectors.schema_version_id', $schemaVersionId)
            ->count();
    }

    /**
     * Изменить `schema_json` ЧЕРНОВОЙ версии.
     *
     * Инкремент `revision` обязателен: иначе
     * `trg_schema_version_lifecycle_guard` отвечает
     * «Draft content changes must increment revision».
     *
     * @param  array<string, mixed>  $schema
     */
    private function mutateSchema(int $schemaVersionId, array $schema): void
    {
        DB::table('hall_schema_versions')
            ->where('id', $schemaVersionId)
            ->update([
                'schema_json' => json_encode($schema),
                'revision' => DB::raw('COALESCE(revision, 1) + 1'),
            ]);
    }

    private function versionModel(int $schemaVersionId): HallSchemaVersion
    {
        return HallSchemaVersion::query()->findOrFail($schemaVersionId);
    }

    /** Сеанс без инвентаря — чтобы позвать сервис напрямую. */
    private function makeSessionModel(int $hallId, int $schemaVersionId): Session
    {
        $now = now()->toDateTimeString();

        $sessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => $this->eventId,
            'venue_id' => $this->venueId,
            'hall_id' => $hallId,
            'schema_version_id' => $schemaVersionId,
            'starts_at' => '2026-12-01 19:00:00',
            'timezone' => 'Asia/Almaty',
            'status' => 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Session::query()->findOrFail($sessionId);
    }

    /**
     * Зал с ЕДИНСТВЕННОЙ черновой версией схемы.
     *
     * Отдельный зал нужен потому, что `uq_schema_one_draft_per_hall` и
     * `uq_schema_one_published_per_hall` не дают держать у одного зала больше
     * одной версии в каждом из этих статусов, а черновик — единственный статус,
     * у которого `schema_json` вообще изменяем.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: int, 1: int}  [hallId, schemaVersionId]
     */
    private function seedDraftHall(array $schema, string $name = 'Черновой зал'): array
    {
        $hallId = $this->seedHall($this->venueId, $name);

        return [$hallId, $this->seedSchema($hallId, $schema, 'draft')];
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
     * Версия схемы зала. У зала допустима ровно одна версия на статус
     * (`uq_schema_one_draft_per_hall` / `uq_schema_one_published_per_hall`).
     *
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

    /**
     * Администратор организации — то, что требует middleware `admin`
     * (`StaffRole::isStaff()` читает `user_roles`, а не колонку в `users`).
     */
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

        // `roles.slug` уникален — берём существующую роль, если тест уже её создал.
        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
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

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }
}
