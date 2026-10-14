<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentSettings;
use Tests\TestCase;

/**
 * Настройки приёма платежей в админке (ЮKassa).
 *
 * ЗАЩИЩАЕТСЯ РОВНО ТО, ЧЕМ ЭТА ФИЧА ОТЛИЧАЕТСЯ ОТ .env
 *   1. Значения из таблицы `settings` применяются к runtime-конфигу и
 *      ПЕРЕКРЫВАЮТ .env — иначе экран был бы украшением.
 *   2. Секреты хранятся зашифрованными и НИКОГДА не возвращаются в API —
 *      иначе DevTools в админке отдавали бы ключи приёма денег.
 *   3. Доступ — только сотрудник.
 */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): PaymentSettings
    {
        return app(PaymentSettings::class);
    }

    private function plainUser(string $email = 'buyer@example.com'): \Nabilet\Modules\Core\Users\Models\User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return \Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($id);
    }

    private function staffUser(): \Nabilet\Modules\Core\Users\Models\User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'name' => 'Staff Org',
            'slug' => 'staff-org-' . \Illuminate\Support\Str::random(6),
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

        return \Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($user->id);
    }

    private function staffHeaders(): array
    {
        $staff = $this->staffUser();

        return ['Authorization' => 'Bearer ' . $this->app->make(\Nabilet\Modules\Auth\Services\SessionIssuer::class)->issue($staff->id)];
    }

    // ── runtime-конфиг ──

    public function test_saved_settings_override_env_in_runtime_config(): void
    {
        config([
            'nabilet.payment.yookassa.shop_id' => 'env-shop',
            'nabilet.payment.yookassa.secret_key' => 'env-secret',
            'nabilet.payment.demo_mode' => false,
            'nabilet.payment.default_provider' => 'yookassa',
        ]);

        $this->settings()->save([
            PaymentSettings::KEY_SHOP_ID => 'db-shop',
            PaymentSettings::KEY_SECRET_KEY => 'db-secret',
            PaymentSettings::KEY_DEMO_MODE => true,
            PaymentSettings::KEY_DEFAULT_PROVIDER => 'yookassa',
        ]);

        self::assertSame('db-shop', config('nabilet.payment.yookassa.shop_id'));
        self::assertSame('db-secret', config('nabilet.payment.yookassa.secret_key'));
        self::assertTrue(config('nabilet.payment.demo_mode'));
    }

    public function test_absent_keys_leave_env_values_alone(): void
    {
        config([
            'nabilet.payment.yookassa.shop_id' => 'env-shop',
            'nabilet.payment.demo_mode' => false,
        ]);

        // Запись только demo_mode — shop_id из .env не трогается: экран не
        // должен затирать ключи, которых администратор не касался.
        $this->settings()->save([PaymentSettings::KEY_DEMO_MODE => true]);

        self::assertSame('env-shop', config('nabilet.payment.yookassa.shop_id'));
        self::assertTrue(config('nabilet.payment.demo_mode'));
    }

    // ── секреты ──

    public function test_secrets_are_stored_encrypted_and_never_returned_to_admin(): void
    {
        $this->settings()->save([
            PaymentSettings::KEY_SECRET_KEY => 'live-secret-123',
            PaymentSettings::KEY_WEBHOOK_SECRET => 'hook-secret-456',
        ]);

        $row = DB::table('settings')
            ->where('scope', PaymentSettings::SCOPE)
            ->where('setting_key', PaymentSettings::KEY_SECRET_KEY)
            ->firstOrFail();

        self::assertTrue((bool) $row->encrypted);
        self::assertStringNotContainsString('live-secret-123', (string) $row->value_json);
        self::assertSame(
            'live-secret-123',
            Crypt::decryptString(json_decode((string) $row->value_json, true)),
        );

        $admin = $this->settings()->forAdmin();

        self::assertArrayNotHasKey(PaymentSettings::KEY_SECRET_KEY, $admin);
        self::assertArrayNotHasKey(PaymentSettings::KEY_WEBHOOK_SECRET, $admin);
        self::assertTrue($admin['secret_key_set']);
        self::assertTrue($admin['webhook_secret_set']);
        self::assertStringNotContainsString('live-secret-123', json_encode($admin, JSON_THROW_ON_ERROR));
    }

    public function test_an_empty_secret_keeps_the_old_one_and_an_explicit_clear_removes_it(): void
    {
        // .env-фон чистый: иначе `secret_key_set` подхватил бы env-значение и
        // проверка «секрета нет» зависела бы от машины.
        config(['nabilet.payment.yookassa.secret_key' => '']);

        $this->settings()->save([PaymentSettings::KEY_SECRET_KEY => 'keep-me']);

        // Обычное сохранение формы с пустым полем секрета не должно его стирать:
        // иначе владелец молча ломал бы приём платежей.
        $this->settings()->save([PaymentSettings::KEY_SECRET_KEY => '']);

        self::assertTrue($this->settings()->forAdmin()['secret_key_set']);

        // Явный флаг удаления — единственный способ убрать секрет. Долговечный
        // эффект — строка в таблице: runtime-конфиг в текущем процессе мог
        // удержать прежнее значение до следующего бутстрапа (тот же порядок,
        // что у пароля SMTP), поэтому проверяется именно хранилище.
        $this->settings()->save([], [PaymentSettings::KEY_SECRET_KEY]);

        self::assertSame(
            0,
            DB::table('settings')
                ->where('scope', PaymentSettings::SCOPE)
                ->where('setting_key', PaymentSettings::KEY_SECRET_KEY)
                ->count(),
            'После явного удаления записи о секрете в таблице быть не должно.'
        );
    }

    // ── админ-API ──

    public function test_settings_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/payments/settings')->assertStatus(401);
        $this->putJson('/api/v1/admin/payments/settings', ['shop_id' => '123'])->assertStatus(401);
    }

    public function test_a_plain_buyer_cannot_read_or_write_settings(): void
    {
        $buyer = $this->plainUser();
        $token = $this->app->make(\Nabilet\Modules\Auth\Services\SessionIssuer::class)->issue($buyer->id);
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->getJson('/api/v1/admin/payments/settings', $headers)->assertStatus(403);

        $this->putJson('/api/v1/admin/payments/settings', [
            'shop_id' => '123456',
            'secret_key' => 'secret',
        ], $headers)->assertStatus(403);
    }

    public function test_staff_reads_settings_and_updates_them(): void
    {
        config([
            'nabilet.payment.yookassa.shop_id' => 'env-shop',
            'nabilet.payment.yookassa.secret_key' => 'env-secret',
            'nabilet.payment.demo_mode' => false,
        ]);

        // Заголовки собираем один раз: каждый вызов staffHeaders() создаёт
        // пользователя заново, а email уникален.
        $headers = $this->staffHeaders();

        $data = $this->getJson('/api/v1/admin/payments/settings', $headers)
            ->assertOk()
            ->json('data');

        // Значения из .env видны как текущие, но помечены источником.
        self::assertSame('env-shop', $data['shop_id']);
        self::assertTrue($data['shop_id_from_env']);
        self::assertTrue($data['secret_key_set']);
        self::assertFalse($data['demo_mode']);
        self::assertSame('yookassa', $data['default_provider']);
        self::assertContains('yookassa', $data['available_providers']);

        $this->putJson('/api/v1/admin/payments/settings', [
            'shop_id' => '109876',
            'secret_key' => 'live-secret',
            'demo_mode' => true,
            'default_provider' => 'yookassa',
        ], $headers)->assertOk();

        // Runtime-конфиг применён сразу же в ответе на сохранение (как у
        // SMTP: платёж после PUT работает с новыми ключами без рестарта).
        self::assertSame('109876', config('nabilet.payment.yookassa.shop_id'));
        self::assertSame('live-secret', config('nabilet.payment.yookassa.secret_key'));
        self::assertTrue(config('nabilet.payment.demo_mode'));

        // Панель показывает «из БД», а не «из .env».
        $updated = $this->getJson('/api/v1/admin/payments/settings', $headers)
            ->assertOk()
            ->json('data');

        self::assertSame('109876', $updated['shop_id']);
        self::assertFalse($updated['shop_id_from_env']);
        self::assertTrue($updated['demo_mode']);
    }

    public function test_update_rejects_an_unregistered_provider(): void
    {
        // Валидация по реестру, а не по `in:yookassa`: Сбербанк появится в
        // реестре — и форма начнёт его принимать без правок этого теста.
        $this->putJson('/api/v1/admin/payments/settings', [
            'default_provider' => 'sberbank',
        ], $this->staffHeaders())->assertStatus(422);
    }

    public function test_the_registry_lists_what_the_form_offers(): void
    {
        self::assertContains('yookassa', app(PaymentProviderRegistry::class)->names());
        self::assertTrue(app(PaymentProviderRegistry::class)->has('yookassa'));
        self::assertFalse(app(PaymentProviderRegistry::class)->has('sberbank'));
    }
}
