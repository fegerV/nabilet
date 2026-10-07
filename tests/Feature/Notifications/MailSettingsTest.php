<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Notifications\Services\MailSettings;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_smtp_settings_and_applies_them_to_runtime_config(): void
    {
        app(MailSettings::class)->save([
            'host' => 'smtp.example.ru',
            'port' => 465,
            'username' => 'noreply@example.ru',
            'password' => 'secret-value',
            'encryption' => 'ssl',
            'from_address' => 'noreply@example.ru',
            'from_name' => 'NABILET',
        ]);

        self::assertSame('smtp', config('mail.default'));
        self::assertSame('smtp.example.ru', config('mail.mailers.smtp.host'));
        self::assertSame(465, config('mail.mailers.smtp.port'));
        self::assertSame('smtps', config('mail.mailers.smtp.scheme'));
        self::assertSame('noreply@example.ru', config('mail.from.address'));
    }

    public function test_password_is_stored_encrypted_and_never_returned_to_admin(): void
    {
        $settings = app(MailSettings::class);

        $settings->save([
            'host' => 'smtp.example.ru',
            'password' => 'super-secret-password',
        ]);

        // В БД — шифротекст, не открытый пароль.
        $row = DB::table('settings')
            ->where('scope', MailSettings::SCOPE)
            ->where('setting_key', 'password')
            ->firstOrFail();

        self::assertTrue((bool) $row->encrypted);
        self::assertStringNotContainsString('super-secret-password', (string) $row->value_json);
        self::assertSame(
            'super-secret-password',
            Crypt::decryptString(json_decode((string) $row->value_json, true)),
        );

        // Наружу уходит только признак, что пароль задан.
        $admin = $settings->forAdmin();
        self::assertTrue($admin['password_set']);
        self::assertArrayNotHasKey('password', $admin);
        self::assertStringNotContainsString('super-secret-password', json_encode($admin, JSON_THROW_ON_ERROR));
    }

    public function test_empty_password_keeps_existing_one_and_explicit_clear_removes_it(): void
    {
        $settings = app(MailSettings::class);
        $settings->save(['host' => 'smtp.example.ru', 'password' => 'keep-me']);

        // Обычное сохранение формы с пустым полем пароля не должно его стирать:
        // иначе администратор молча ломал бы работающий SMTP.
        $settings->save(['host' => 'smtp.example.ru', 'password' => '']);

        self::assertTrue($settings->forAdmin()['password_set']);

        // Явный флаг удаления — единственный способ убрать пароль.
        $settings->save([], clearPassword: true);

        self::assertFalse($settings->forAdmin()['password_set']);
    }

    public function test_empty_host_leaves_env_mailer_untouched(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'env-host']);

        app(MailSettings::class)->save(['username' => 'noreply@example.ru']);

        // Хост не задан — значит админ ещё не включал SMTP, и переключать
        // транспорт на пустой хост нельзя.
        self::assertSame('log', config('mail.default'));
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/mail/settings')->assertStatus(401);
        $this->putJson('/api/v1/admin/mail/settings', ['host' => 'x'])->assertStatus(401);
        $this->postJson('/api/v1/admin/mail/test', ['recipient' => 'a@b.test'])->assertStatus(401);
    }

    public function test_setting_model_does_not_write_absent_created_at_column(): void
    {
        // Регресс: таблица `settings` несёт только updated_at, и модель с
        // включёнными timestamps падала на «Unknown column 'created_at'».
        $setting = new Setting([
            'scope' => 'test',
            'setting_key' => 'k',
            'value_json' => json_encode('v'),
            'encrypted' => false,
        ]);
        $setting->updated_at = now();
        $setting->save();

        self::assertNotNull($setting->id);
        self::assertSame(1, Setting::query()->where('scope', 'test')->count());
    }
}
