<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * API обращений субъектов ПДн (152-ФЗ): подача, отслеживание, обработка.
 *
 * ДО ЭТОГО
 *   Домен (`PrivacyRequest`, `PrivacyRequestPolicy`) и модель существовали,
 *   но модуль не имел ни одного HTTP-пути: подать обращение было невозможно
 *   ни гостю, ни пользователю, ни оператору. Здесь проверяется контракт HTTP:
 *
 *   1. гость подаёт обращение (обязателен контактный e-mail), получает
 *      public_id и tracked-статус; payload_json в трекинге НЕ виден;
 *   2. авторизованный подаёт без e-mail — адрес берётся из профиля;
 *   3. дедлайн `due_at` записывается при подаче из конфига
 *      (`nabilet.privacy.request_sla_days`), а не вычисляется при чтении;
 *   4. админ видит очередь (+фильтры), одно обращение с payload_json
 *      и переводит статус по матрице `PrivacyRequestPolicy`: отказ —
 *      409 `PRIVACY_REQUEST_TRANSITION_DENIED`, тот же статус —
 *      200 без записи, терминальный — не оживет.
 */
class PrivacyRequestApiTest extends TestCase
{
    use RefreshDatabase;

    /* ── витрина: подача и трекинг ───────────────────────────────────────── */

    public function test_a_guest_submits_a_request_and_receives_a_trackable_public_id(): void
    {
        $response = $this->postJson('/api/v1/privacy-requests', [
            'type' => 'erasure',
            'email' => 'guest@example.com',
            'message' => 'Удалите мою учётную запись и все данные.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'requested');
        $response->assertJsonPath('data.type', 'erasure');
        $response->assertJsonPath('data.completed_at', null);

        $publicId = (string) $response->json('data.public_id');
        $this->assertNotSame('', $publicId);

        // Строка в базе: гостевое обращение не привязано к аккаунту,
        // контакт живёт в payload_json — это единственный канал связи.
        $row = DB::table('privacy_requests')->where('public_id', $publicId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);

        // DB::table отдаёт JSON-колонку строкой — декодируем явно.
        $payload = json_decode((string) $row->payload_json, true) ?? [];
        $this->assertSame('guest@example.com', $payload['contact_email'] ?? null);
        $this->assertSame('Удалите мою учётную запись и все данные.', $payload['message'] ?? null);

        // Дедлайн записан при подаче: умолчание SLA — 30 дней из конфига.
        $this->assertTrue(
            Carbon::parse($row->due_at)->equalTo(Carbon::parse($row->created_at)->addDays(30)),
            'due_at должен равняться created_at + 30 дней (умолчание SLA)'
        );

        // Странице отслеживания виден статус, но НЕ содержимое обращения:
        // public_id уходит в письмо и в ссылку, утечка не должна раскрывать ПДн.
        $track = $this->getJson('/api/v1/privacy-requests/' . $publicId);
        $track->assertOk();
        $track->assertJsonPath('data.status', 'requested');
        $this->assertNull(
            $track->json('data.payload_json'),
            'Публичный трекинг не должен отдавать payload_json (там контактный e-mail)'
        );

        // Неизвестный идентификатор — 404 без признаков существования чужих.
        $this->getJson('/api/v1/privacy-requests/ZZZZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();
    }

    public function test_an_authed_user_submits_without_email_and_the_profile_fills_the_payload(): void
    {
        $user = $this->plainUser('buyer@example.com');

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/privacy-requests', [
            'type' => 'access',
        ]);

        $response->assertCreated();

        // Субъект известен из токена — e-mail не обязателен и НЕ требуется.
        $row = DB::table('privacy_requests')->where('public_id', $response->json('data.public_id'))->first();
        $this->assertSame($user->id, (int) $row->user_id);

        // Контакт берётся из профиля: оператор обязан иметь канал ответа
        // для любого обращения, а не только гостевого.
        $payload = json_decode((string) $row->payload_json, true) ?? [];
        $this->assertSame('buyer@example.com', $payload['contact_email'] ?? null);
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        // «banana» — не обращение по 152-ФЗ: словарь из пяти видов держит
        // и валидация, и CHECK в базе; здесь проверен верхний барьер.
        $this->postJson('/api/v1/privacy-requests', [
            'type' => 'banana',
            'email' => 'guest@example.com',
        ])->assertUnprocessable();
    }

    public function test_a_guest_without_email_is_rejected(): void
    {
        // Гость обязан оставить контакт: отвечать некому — обращение не принимается.
        $this->postJson('/api/v1/privacy-requests', [
            'type' => 'access',
        ])->assertUnprocessable();
    }

    public function test_the_sla_deadline_flows_from_config(): void
    {
        config(['nabilet.privacy.request_sla_days' => 7]);

        $publicId = $this->submitGuestRequest();

        $row = DB::table('privacy_requests')->where('public_id', $publicId)->first();

        $this->assertTrue(
            Carbon::parse($row->due_at)->equalTo(Carbon::parse($row->created_at)->addDays(7)),
            'Число дней задаёт оператор через конфиг — семь должно остаться семью'
        );
    }

    /* ── админка: очередь и жизненный цикл ───────────────────────────────── */

    public function test_admin_endpoints_require_authentication(): void
    {
        // Аноним: auth:api отрезает раньше, чем admin-check успел бы ответить.
        $this->getJson('/api/v1/admin/privacy-requests')->assertUnauthorized();
    }

    public function test_admin_endpoints_forbid_a_plain_buyer(): void
    {
        $this->actingAs($this->plainUser('buyer@example.com'), 'api');

        $this->getJson('/api/v1/admin/privacy-requests')->assertForbidden();
    }

    public function test_the_admin_sees_newest_first_and_can_filter(): void
    {
        $newer = $this->seedRequest('access', 'requested', now());
        $older = $this->seedRequest('erasure', 'in_progress', now()->subHour());

        $this->actingAs($this->staffUser(), 'api');

        $response = $this->getJson('/api/v1/admin/privacy-requests');
        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
        $ids = array_column($response->json('data'), 'public_id');
        $this->assertSame([$newer, $older], $ids, 'Очередь сортируется по подаче, новые сверху');

        // Фильтры — по словарям домена; мусор отсечён валидацией (422).
        $this->getJson('/api/v1/admin/privacy-requests?type=erasure')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $older);

        $this->getJson('/api/v1/admin/privacy-requests?status=in_progress')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $older);

        $this->getJson('/api/v1/admin/privacy-requests?type=banana')->assertUnprocessable();
    }

    public function test_the_admin_sees_the_payload(): void
    {
        $publicId = $this->submitGuestRequest('rectification', 'operator-look@example.com');

        $this->actingAs($this->staffUser(), 'api');

        // Единственное место, где payload_json отдаётся по HTTP: оператору он
        // нужен, чтобы ответить обращению.
        $this->getJson('/api/v1/admin/privacy-requests/' . $publicId)
            ->assertOk()
            ->assertJsonPath('data.payload_json.contact_email', 'operator-look@example.com');

        // Числовая адресация не поддерживается: наружу отдаётся только
        // public_id — «1» валиден для маршрута, но строки с ним нет → 404.
        $this->getJson('/api/v1/admin/privacy-requests/1')->assertNotFound();

        $this->getJson('/api/v1/admin/privacy-requests/ZZZZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();
    }

    public function test_the_operator_walks_the_lifecycle_and_terminal_requests_stay_closed(): void
    {
        $publicId = $this->submitGuestRequest();

        $this->actingAs($this->staffUser(), 'api');

        // Взять в работу: переход по матрице, completed_at ещё не пишется.
        $this->patchJson('/api/v1/admin/privacy-requests/' . $publicId, ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.completed_at', null);

        // Закрыть: терминальный статус обязан записать момент закрытия.
        $closed = $this->patchJson('/api/v1/admin/privacy-requests/' . $publicId, ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($closed->json('data.completed_at'));
        $row = DB::table('privacy_requests')->where('public_id', $publicId)->first();
        $this->assertNotNull($row->completed_at);

        // Оживить закрытое нельзя: reopened запустил бы срок ответа заново
        // молча — 409 с кодом и контекст from/to.
        $denied = $this->patchJson('/api/v1/admin/privacy-requests/' . $publicId, ['status' => 'in_progress'])
            ->assertStatus(409);

        $this->assertSame('PRIVACY_REQUEST_TRANSITION_DENIED', $denied->json('error.code'));
        $this->assertSame('completed', $denied->json('error.details.from'));
        $this->assertSame('in_progress', $denied->json('error.details.to'));

        // Словарь статусов не пропускает мусор и на входе PATCH.
        $this->patchJson('/api/v1/admin/privacy-requests/' . $publicId, ['status' => 'banana'])
            ->assertUnprocessable();

        // Неизвестный public_id → 404.
        $this->patchJson('/api/v1/admin/privacy-requests/ZZZZZZZZZZZZZZZZZZZZZZZZZZ', ['status' => 'completed'])
            ->assertNotFound();
    }

    public function test_an_idempotent_patch_writes_nothing(): void
    {
        $publicId = $this->submitGuestRequest();

        $this->actingAs($this->staffUser(), 'api');

        // Повторный PATCH того же статуса — 200 без записи: пустой UPDATE
        // не должен выглядеть как работа оператора.
        $this->patchJson('/api/v1/admin/privacy-requests/' . $publicId, ['status' => 'requested'])
            ->assertOk()
            ->assertJsonPath('data.status', 'requested');

        $row = DB::table('privacy_requests')->where('public_id', $publicId)->first();
        $this->assertSame('requested', $row->status);
        $this->assertNull($row->completed_at);
    }

    /* ── фикстуры ─────────────────────────────────────────────────────────── */

    /** Гостевая подача через HTTP — реальный маршрут с валидацией и throttle. */
    private function submitGuestRequest(
        string $type = 'erasure',
        string $email = 'guest@example.com',
    ): string {
        $response = $this->postJson('/api/v1/privacy-requests', [
            'type' => $type,
            'email' => $email,
        ]);

        $response->assertCreated();

        return (string) $response->json('data.public_id');
    }

    /**
     * Обращение, засеянное напрямую: для списков/фильтров важен порядок подачи
     * и заранее разошедшиеся статусы — HTTP дал бы неразличимые микросекунды.
     *
     * @return string public_id засеянной строки
     */
    private function seedRequest(string $type, string $status, CarbonInterface $createdAt): string
    {
        $publicId = (string) Str::ulid()->toBase32();

        DB::table('privacy_requests')->insert([
            'public_id' => $publicId,
            'user_id' => null,
            'type' => $type,
            'status' => $status,
            'due_at' => $createdAt->copy()->addDays(30),
            'payload_json' => json_encode(['contact_email' => 'seed@example.com']),
            'created_at' => $createdAt,
            'completed_at' => null,
        ]);

        return $publicId;
    }

    private function plainUser(string $email = 'buyer@example.com'): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * Сотрудник: `StaffRole::isStaff()` читает `user_roles`, а не колонку в
     * `users`. Организация нужна только как обязательный ключ членства.
     */
    private function staffUser(): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Staff Org',
            'slug' => 'staff-org',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = $this->plainUser('staff@example.com');

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $this->adminRoleId(),
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $this->adminRoleId(),
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($user->id);
    }

    private function adminRoleId(): int
    {
        $existing = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('roles')->insertGetId([
            'name' => 'Администратор',
            'slug' => 'admin',
        ]);
    }
}
