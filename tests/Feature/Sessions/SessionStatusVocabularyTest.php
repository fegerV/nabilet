<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Словарь статусов сеанса: один владелец вместо копии в контроллере.
 *
 * Повод — PATCH `/api/v1/sessions/{id}` с полем `status`. Правило валидации
 * было написано своей строкой:
 *
 *     'status' => ['nullable', 'string', 'in:scheduled,on_sale,held,completed,cancelled']
 *
 * и разошлось с `SessionStateMachine` и с `ck_sessions_status` в ОБЕ стороны:
 *
 *  - `held` — состояния сеанса не существует. Это статус позиции инвентаря
 *    (`InventoryItemStateMachine::HELD`); строка попала сюда копипастой из
 *    соседнего словаря. Пользователь получал не 422, а 500 INTERNAL_ERROR:
 *    валидация Laravel проходила, отказ приходил уже от MySQL.
 *  - `draft`, `sold_out`, `closed` — обязательные состояния продаж, они есть
 *    и в машине, и в CHECK-ограничении, но форма не могла их выставить (422).
 *
 * Тест проходит через живой HTTP-контур: ломает он именно на границе запроса,
 * и проверить это надо запросом.
 */
final class SessionStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $eventId;

    private int $hallId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin($this->organizationId);
        $this->eventId = $this->seedEvent($this->organizationId);

        $venueId = $this->seedVenue($this->organizationId);
        $this->hallId = $this->seedHall($venueId);
        $this->seedSchema($this->hallId, $this->seatedSchema(1, 1));
    }

    // ── 1. Весь словарь машины состояний принимается ────────────────────────

    public function test_every_state_of_the_machine_is_accepted_by_the_api(): void
    {
        $sessionId = $this->createSession();

        foreach (SessionStateMachine::all() as $status) {
            $response = $this->patchJson("/api/v1/sessions/{$sessionId}", ['status' => $status]);

            $response->assertStatus(200);
            $this->assertSame(
                $status,
                (string) DB::table('sessions')->where('id', $sessionId)->value('status'),
                "статус {$status} принят ответом, но в БД лёг другой"
            );
        }
    }

    public function test_a_new_session_defaults_to_the_machine_state_when_status_is_absent(): void
    {
        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'hall_id' => $this->hallId,
            'starts_at' => '2026-12-01 19:00:00',
        ]);

        $response->assertStatus(201);

        $this->assertSame(
            SessionStateMachine::SCHEDULED,
            (string) $response->json('data.status'),
            'дефолт должен браться из машины состояний, а не из строки в контроллере'
        );
    }

    // ── 2. Чужие значения не доходят до CHECK-ограничения ───────────────────

    /**
     * `held` — главный регресс: раньше он проходил валидацию и падал 500-м
     * уже в MySQL (`ck_sessions_status`). Ошибка пользователя должна
     * разбираться на границе запроса, а не в журнале.
     */
    #[DataProvider('foreignStatuses')]
    public function test_a_status_from_another_vocabulary_is_refused_with_422(string $status): void
    {
        $sessionId = $this->createSession();

        $response = $this->patchJson("/api/v1/sessions/{$sessionId}", ['status' => $status]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Не `assertJsonValidationErrors()`: он ищет ключ `errors`, а §66-конверт
        // кладёт карту полей в `error.details.fields` (`ValidationError::toResponse()`).
        $this->assertArrayHasKey('status', (array) $response->json('error.details.fields'));

        $this->assertSame(
            SessionStateMachine::SCHEDULED,
            (string) DB::table('sessions')->where('id', $sessionId)->value('status'),
            'отказ не должен менять статус сеанса'
        );
    }

    /** @return array<string, array{string}> */
    public static function foreignStatuses(): array
    {
        return [
            'held (состояние позиции инвентаря)' => ['held'],
            'held с пробелом — его срежет TrimStrings' => [' held'],
            'active (состояние зала)' => ['active'],
            'published (состояние версии схемы)' => ['published'],
            'completed с опечаткой' => ['comleted'],
            'пустая строка' => [''],
        ];
    }

    // ── 3. Создание сеанса слушает тот же словарь ───────────────────────────

    public function test_store_accepts_the_full_vocabulary_and_refuses_held(): void
    {
        $payload = [
            'event_id' => $this->eventId,
            'hall_id' => $this->hallId,
            'starts_at' => '2026-12-01 19:00:00',
        ];

        $this->postJson('/api/v1/sessions', $payload + ['status' => SessionStateMachine::ON_SALE])
            ->assertStatus(201);

        $this->postJson('/api/v1/sessions', $payload + ['status' => 'held'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ── Фикстуры ────────────────────────────────────────────────────────────

    private function createSession(): int
    {
        $response = $this->postJson('/api/v1/sessions', [
            'event_id' => $this->eventId,
            'hall_id' => $this->hallId,
            'starts_at' => '2026-12-01 19:00:00',
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** @param  array<string, mixed>  $schema */
    private function seedSchema(int $hallId, array $schema): int
    {
        $version = HallSchemaVersion::create([
            'hall_id' => $hallId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => now(),
            'schema_json' => $schema,
        ]);

        return (int) $version->id;
    }

    /** @return array<string, mixed> */
    private function seatedSchema(int $rowsCount, int $seatsPerRow): array
    {
        $rows = [];

        for ($r = 1; $r <= $rowsCount; $r++) {
            $seats = [];

            for ($s = 1; $s <= $seatsPerRow; $s++) {
                $seats[] = ['number' => (string) $s, 'type' => 'standard', 'status' => 'active'];
            }

            $rows[] = ['number' => (string) $r, 'seats' => $seats];
        }

        return ['sectors' => [[
            'name' => 'Партер',
            'code' => 'P',
            'type' => 'seated',
            'rows' => $rows,
        ]]];
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

    private function seedVenue(int $organizationId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'КЗ «Тестовый»',
            'slug' => 'venue-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedHall(int $venueId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Большой зал',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Администратор организации — то, что требует middleware `admin`
     * (`StaffRole::isStaff()` читает `user_roles`), плюс членство в
     * `user_organization`, из которого берётся организация запроса.
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
