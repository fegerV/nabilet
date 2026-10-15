<?php

declare(strict_types=1);

namespace Tests\Feature\Matrix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Matrix\Services\MatrixSettings;
use Tests\TestCase;

/**
 * Настройки канала Matrix в админке.
 *
 * ЗАЩИЩАЕТСЯ РОВНО ТО, ЧЕМ ЭТА ФИЧА ОТЛИЧАЕТСЯ ОТ .env
 *   1. Значения из таблицы `settings` применяются к runtime-конфигу и
 *      ПЕРЕКРЫВАЮТ .env — иначе экран был бы украшением.
 *   2. Токен доступа к Synapse хранится зашифрованным и НИКОГДА не возвращается
 *      в API — иначе DevTools в админке отдавали бы полный доступ к аккаунту
 *      бота, который не отзывается вместе с сессией.
 *   3. Формат адреса и комнаты проверяется на сервере: админка — не
 *      единственный вход в API.
 *   4. Доступ — только сотрудник: покупатель получает 403.
 *   5. Тест-отправка уходит `PUT` в Client-Server API Synapse с Bearer-токеном
 *      и телом `m.text`, а при незаполненных настройках отвечает `ok: false`
 *      с внятной причиной, а не 500.
 */
class MatrixSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): MatrixSettings
    {
        return app(MatrixSettings::class);
    }

    private function plainUser(string $email = 'buyer@example.com'): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    private function staffUser(): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Staff Org',
            'slug' => 'staff-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = $this->plainUser('staff@example.com');

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($user->id);
    }

    private function staffHeaders(): array
    {
        $staff = $this->staffUser();

        return ['Authorization' => 'Bearer ' . app(SessionIssuer::class)->issue($staff->id)];
    }

    private function configure(): void
    {
        $this->settings()->save([
            MatrixSettings::KEY_HOMESERVER => 'https://matrix.example.org',
            MatrixSettings::KEY_ROOM_ID => '!room:example.org',
            MatrixSettings::KEY_ACCESS_TOKEN => 'matrix-token',
            MatrixSettings::KEY_ENABLED => true,
            MatrixSettings::KEY_NOTIFY_ORDERS => true,
        ]);
    }

    // ── runtime-конфиг ───────────────────────────────────────────────────────

    public function test_saved_settings_override_env_in_runtime_config(): void
    {
        config([
            'nabilet.matrix.homeserver_url' => 'env-hs',
            'nabilet.matrix.room_id' => '!env:server',
            'nabilet.matrix.access_token' => 'env-token',
            'nabilet.matrix.enabled' => false,
        ]);

        $this->configure();

        self::assertSame('https://matrix.example.org', config('nabilet.matrix.homeserver_url'));
        self::assertSame('!room:example.org', config('nabilet.matrix.room_id'));
        self::assertSame('matrix-token', config('nabilet.matrix.access_token'));
        self::assertTrue(config('nabilet.matrix.enabled'));
    }

    public function test_absent_keys_leave_env_values_alone(): void
    {
        config([
            'nabilet.matrix.homeserver_url' => 'env-hs',
            'nabilet.matrix.enabled' => false,
        ]);

        // Запись только токена — адрес из .env не трогается: экран не должен
        // затирать значения, которых администратор не касался.
        $this->settings()->save([MatrixSettings::KEY_ACCESS_TOKEN => 'matrix-token']);

        self::assertSame('env-hs', config('nabilet.matrix.homeserver_url'));
        self::assertFalse(config('nabilet.matrix.enabled'));
    }

    // ── секрет ───────────────────────────────────────────────────────────────

    public function test_token_is_stored_encrypted_and_never_returned_to_admin(): void
    {
        $this->settings()->save([MatrixSettings::KEY_ACCESS_TOKEN => 'syt_live_token_123']);

        $row = DB::table('settings')
            ->where('scope', MatrixSettings::SCOPE)
            ->where('setting_key', MatrixSettings::KEY_ACCESS_TOKEN)
            ->firstOrFail();

        self::assertTrue((bool) $row->encrypted);
        self::assertStringNotContainsString('syt_live_token_123', (string) $row->value_json);
        self::assertSame(
            'syt_live_token_123',
            Crypt::decryptString(json_decode((string) $row->value_json, true)),
        );

        $admin = $this->settings()->forAdmin();

        self::assertArrayNotHasKey(MatrixSettings::KEY_ACCESS_TOKEN, $admin);
        self::assertTrue($admin['access_token_set']);
        self::assertStringNotContainsString('syt_live_token_123', json_encode($admin, JSON_THROW_ON_ERROR));
    }

    public function test_an_empty_token_keeps_the_old_one_and_an_explicit_clear_removes_it(): void
    {
        // .env-фон чистый: иначе `access_token_set` подхватил бы env-значение и
        // проверка «токена нет» зависела бы от машины.
        config(['nabilet.matrix.access_token' => '']);

        $this->settings()->save([MatrixSettings::KEY_ACCESS_TOKEN => 'keep-me']);

        // Обычное сохранение формы с пустым полем токена не должно его стирать:
        // иначе администратор молча ломал бы работающий канал.
        $this->settings()->save([MatrixSettings::KEY_ACCESS_TOKEN => '']);

        self::assertTrue($this->settings()->forAdmin()['access_token_set']);

        // Явный флаг удаления — единственный способ убрать токен.
        $this->settings()->save([], clearToken: true);

        self::assertSame(
            0,
            DB::table('settings')
                ->where('scope', MatrixSettings::SCOPE)
                ->where('setting_key', MatrixSettings::KEY_ACCESS_TOKEN)
                ->count(),
            'После явного удаления записи о токене в таблице быть не должно.'
        );

        // Свежий бутстрап: runtime-конфиг в текущем процессе удерживает прежнее
        // значение до следующего запроса (тот же порядок, что у пароля SMTP),
        // поэтому «токена нет» проверяется на чистом фоне.
        config(['nabilet.matrix.access_token' => '']);

        self::assertFalse($this->settings()->forAdmin()['access_token_set']);
    }

    public function test_configured_requires_all_three_values(): void
    {
        config(['nabilet.matrix.access_token' => '', 'nabilet.matrix.homeserver_url' => '', 'nabilet.matrix.room_id' => '']);

        self::assertFalse($this->settings()->isConfigured());

        $this->settings()->save([
            MatrixSettings::KEY_HOMESERVER => 'https://matrix.example.org',
            MatrixSettings::KEY_ROOM_ID => '!room:example.org',
        ]);

        // Токена ещё нет — отправка невозможна.
        self::assertFalse($this->settings()->isConfigured());

        $this->settings()->save([MatrixSettings::KEY_ACCESS_TOKEN => 'matrix-token']);

        self::assertTrue($this->settings()->isConfigured());
    }

    // ── админ-API ────────────────────────────────────────────────────────────

    public function test_settings_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/matrix/settings')->assertStatus(401);
        $this->putJson('/api/v1/admin/matrix/settings', ['room_id' => '!r:s'])->assertStatus(401);
        $this->postJson('/api/v1/admin/matrix/test')->assertStatus(401);
    }

    public function test_a_plain_buyer_cannot_read_or_write_settings(): void
    {
        $buyer = $this->plainUser();
        $headers = ['Authorization' => 'Bearer ' . app(SessionIssuer::class)->issue($buyer->id)];

        $this->getJson('/api/v1/admin/matrix/settings', $headers)->assertStatus(403);
        $this->putJson('/api/v1/admin/matrix/settings', ['room_id' => '!r:s'], $headers)->assertStatus(403);
        $this->postJson('/api/v1/admin/matrix/test', [], $headers)->assertStatus(403);
    }

    public function test_staff_reads_and_updates_settings(): void
    {
        config([
            'nabilet.matrix.homeserver_url' => 'env-hs',
            'nabilet.matrix.room_id' => '!env:server',
            'nabilet.matrix.access_token' => 'env-token',
            'nabilet.matrix.enabled' => false,
            'nabilet.matrix.notify_orders' => true,
        ]);

        // Заголовки собираем один раз: каждый вызов staffHeaders() создаёт
        // пользователя заново, а email уникален.
        $headers = $this->staffHeaders();

        $data = $this->getJson('/api/v1/admin/matrix/settings', $headers)
            ->assertOk()
            ->json('data');

        // Значения из .env видны как текущие, но помечены источником.
        self::assertSame('env-hs', $data['homeserver_url']);
        self::assertTrue($data['homeserver_url_from_env']);
        self::assertTrue($data['access_token_set']);
        self::assertFalse($data['enabled']);
        self::assertTrue($data['configured']);

        $this->putJson('/api/v1/admin/matrix/settings', [
            'homeserver_url' => 'https://matrix.example.org',
            'room_id' => '!room:example.org',
            'access_token' => 'live-token',
            'enabled' => true,
            'notify_orders' => false,
        ], $headers)->assertOk();

        // Runtime-конфиг применён сразу же в ответе на сохранение.
        self::assertSame('https://matrix.example.org', config('nabilet.matrix.homeserver_url'));
        self::assertSame('!room:example.org', config('nabilet.matrix.room_id'));
        self::assertSame('live-token', config('nabilet.matrix.access_token'));
        self::assertTrue(config('nabilet.matrix.enabled'));
        self::assertFalse(config('nabilet.matrix.notify_orders'));

        $updated = $this->getJson('/api/v1/admin/matrix/settings', $headers)
            ->assertOk()
            ->json('data');

        self::assertSame('!room:example.org', $updated['room_id']);
        self::assertFalse($updated['room_id_from_env']);
        self::assertTrue($updated['enabled']);
        self::assertFalse($updated['notify_orders']);
        self::assertTrue($updated['configured']);
    }

    public function test_update_rejects_a_malformed_homeserver(): void
    {
        $this->putJson('/api/v1/admin/matrix/settings', [
            'homeserver_url' => 'ftp://matrix.example.org',
        ], $this->staffHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.homeserver_url.0', fn ($v): bool => is_string($v));
    }

    public function test_update_rejects_a_room_alias(): void
    {
        // Алиас вместо ID — самая частая ошибка при копировании из Element X.
        $this->putJson('/api/v1/admin/matrix/settings', [
            'room_id' => '#name:matrix.example.org',
        ], $this->staffHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.room_id.0', fn ($v): bool => is_string($v));
    }

    public function test_clearing_the_address_removes_the_override_and_falls_back_to_env(): void
    {
        $this->configure();

        $this->putJson('/api/v1/admin/matrix/settings', [
            'homeserver_url' => '',
            'room_id' => '',
        ], $this->staffHeaders())->assertOk();

        // Пустое поле формы приходит как `null` (`ConvertEmptyStringsToNull`) и
        // значит «стёрто»: переопределение уходит из таблицы, а не записывается
        // пустой строкой. Иначе администратор не смог бы очистить поле — форма
        // молча сохраняла бы прежнее значение.
        self::assertSame(
            0,
            DB::table('settings')
                ->where('scope', MatrixSettings::SCOPE)
                ->whereIn('setting_key', [MatrixSettings::KEY_HOMESERVER, MatrixSettings::KEY_ROOM_ID])
                ->count(),
        );

        // Свежий бутстрап: значение снова берётся из .env (runtime-конфиг в
        // текущем процессе удерживает прежнее значение до следующего запроса).
        config(['nabilet.matrix.homeserver_url' => 'env-hs']);

        self::assertSame('env-hs', $this->settings()->homeserverUrl());
    }

    // ── тест-отправка ────────────────────────────────────────────────────────

    public function test_test_endpoint_reports_a_missing_configuration(): void
    {
        config([
            'nabilet.matrix.homeserver_url' => '',
            'nabilet.matrix.room_id' => '',
            'nabilet.matrix.access_token' => '',
        ]);

        $data = $this->postJson('/api/v1/admin/matrix/test', [], $this->staffHeaders())
            ->assertOk()
            ->json('data');

        self::assertFalse($data['ok']);
        self::assertIsString($data['error']);
        self::assertStringContainsString('не настроен', $data['error']);
    }

    public function test_test_endpoint_posts_to_the_configured_room(): void
    {
        Http::fake(['*' => Http::response(['event_id' => '$test'], 200)]);

        $this->configure();

        $data = $this->postJson('/api/v1/admin/matrix/test', [], $this->staffHeaders())
            ->assertOk()
            ->json('data');

        self::assertTrue($data['ok']);
        self::assertNull($data['error']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PUT'
                && str_starts_with($request->url(), 'https://matrix.example.org/_matrix/client/v3/rooms/')
                && str_contains($request->url(), '/send/m.room.message/')
                && $request->hasHeader('Authorization', 'Bearer matrix-token')
                && ($request['msgtype'] ?? null) === 'm.text'
                && is_string($request['body'] ?? null);
        });
    }

    public function test_test_endpoint_reports_a_synapse_error_instead_of_throwing(): void
    {
        Http::fake(['*' => Http::response(['errcode' => 'M_FORBIDDEN', 'error' => 'Invalid token'], 403)]);

        $this->configure();

        $data = $this->postJson('/api/v1/admin/matrix/test', [], $this->staffHeaders())
            ->assertOk()
            ->json('data');

        self::assertFalse($data['ok']);
        self::assertStringContainsString('403', (string) $data['error']);
    }
}
