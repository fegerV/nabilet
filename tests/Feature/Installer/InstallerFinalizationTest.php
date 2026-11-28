<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Core\Users\Support\StaffRole;
use Nabilet\Modules\Installer\Http\Controllers\InstallerController;
use Nabilet\Modules\Installer\Support\InstallKey;
use Tests\TestCase;

/**
 * Точечные проверки финализации установки 1.16.3.
 */
final class InstallerFinalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        InstallKey::forget();
        @unlink(storage_path('install.lock'));
        parent::tearDown();
    }

    public function test_env_makes_only_the_created_owner_a_platform_admin(): void
    {
        $env = $this->env(['admin_email' => 'Owner@Example.test']);

        $this->assertStringContainsString('NABILET_PLATFORM_ADMINS=owner@example.test', $env);
        $this->assertStringContainsString('APP_TIMEZONE=Asia/Yekaterinburg', $env);
        $this->assertStringContainsString('NABILET_LOCALE=ru', $env);
        $this->assertStringContainsString('NABILET_REGION="Сургут"', $env);
        $this->assertStringContainsString('NABILET_CURRENCY=RUB', $env);
        // Приватный ключ не имеет поля в мастере и не может попасть в .env.
        $this->assertStringNotContainsString('PRIVATE KEY', $env);
    }

    public function test_public_key_and_feed_url_are_written_as_safe_single_line_values(): void
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAygoafHykt8Iq03so2vx3\n"
            . "kcW8qRiU/GhLfQiHCHc09Egje1PludJ+xPBndJtcC/So2ITVrlguq72lYxnXD7YG\n"
            . "E6azegTMAhcbvuj8gLscs9GDiINUaCOtRg24KNcaX7s4CKW5I4lKQ3GM2dzT1FOb\n"
            . "AuKXCflApZ8VK6vUc5vPpvqYfVNyE76AZeYEmAoPVgqXAf0NLFP74sZzgXvFU609\n"
            . "X5uOsCtKCf0oQB4d34t3In382oRhOX30VfABEBoL2jOMiRPDB/QgjH1aYTzeXH3N\n"
            . "QzS/7SL/IYR7/E2FmwgpZwVIEiYrgpUy1mNgfsS7xxRv13hyLJN2HeoPqgKhcp4M\n"
            . "fQIDAQAB\n-----END PUBLIC KEY-----\n";
        $env = $this->env([
            'updates_public_key' => $pem,
            'updates_feed_url' => 'https://updates.example.test/manifest.json',
        ]);

        $this->assertMatchesRegularExpression(
            '/^NABILET_UPDATE_PUBLIC_KEY=[A-Za-z0-9+\/=]+$/m',
            $env,
        );
        $this->assertStringContainsString('NABILET_UPDATE_FEED_URL=https://updates.example.test/manifest.json', $env);
        $this->assertStringNotContainsString('BEGIN PUBLIC KEY', $env);
    }

    public function test_installer_retry_does_not_duplicate_admin_organization_or_roles(): void
    {
        $invoke = static fn (string $method, array $args): mixed => (new \ReflectionMethod(InstallerController::class, $method))
            ->invokeArgs(new InstallerController(), $args);

        $invoke('createAdminUser', ['owner@retry.test', 'long-enough-password', 'Retry Org']);
        $invoke('createAdminUser', ['owner@retry.test', 'long-enough-password', 'Retry Org']);

        $this->assertSame(1, (int) DB::table('users')->where('email', 'owner@retry.test')->count());
        $this->assertSame(1, (int) DB::table('organizations')->where('name', 'Retry Org')->count());
        $userId = (int) DB::table('users')->where('email', 'owner@retry.test')->value('id');
        $orgId = (int) DB::table('organizations')->where('name', 'Retry Org')->value('id');
        $this->assertSame(1, (int) DB::table('user_organization')->where('user_id', $userId)->where('organization_id', $orgId)->count());
        $this->assertSame(1, (int) DB::table('user_roles')->where('user_id', $userId)->where('organization_id', $orgId)->count());
    }

    public function test_final_diagnostics_separates_critical_errors_from_warnings(): void
    {
        $method = new \ReflectionMethod(InstallerController::class, 'finalDiagnostics');
        $diag = $method->invoke(new InstallerController(), $this->baseData());

        $this->assertIsArray($diag['critical']);
        $this->assertIsArray($diag['warnings']);
        $this->assertIsBool($diag['ok']);
        $this->assertContains('Файл storage/install.lock не создан — установщик остаётся открытым.', $diag['critical']);
        $this->assertContains('Настройте cron: php artisan schedule:run каждую минуту.', $diag['warnings']);
        $this->assertFalse($diag['ok']);
    }

    public function test_install_key_generation_is_stable_and_constant_time_compared(): void
    {
        $first = InstallKey::ensure();
        $second = InstallKey::ensure();
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertTrue(InstallKey::verify($first));
        $this->assertFalse(InstallKey::verify(str_repeat('0', 64)));
    }

    public function test_platform_admin_is_not_inherited_by_an_ordinary_org_admin(): void
    {
        config(['nabilet.platform_admins' => ['owner@split.test'], 'nabilet.single_tenant_admin' => false]);
        $now = now()->toDateTimeString();
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        $orgId = (int) DB::table('organizations')->insertGetId([
            'public_id' => 'org_split_' . bin2hex(random_bytes(8)),
            'name' => 'Org', 'slug' => 'org-' . bin2hex(random_bytes(4)),
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => 'usr_split_' . bin2hex(random_bytes(8)), 'email' => 'ordinary@split.test',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        $user = \Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId);

        // Даже администратор организации не наследует platform-admin.
        $this->assertFalse(StaffRole::isPlatformAdmin($user));
    }

    /** @param array<string, mixed> $extra @return string */
    private function env(array $extra = []): string
    {
        return (string) (new \ReflectionMethod(InstallerController::class, 'buildEnvContent'))
            ->invokeArgs(new InstallerController(), [array_merge($this->baseData(), $extra), 'base64:AAAA']);
    }

    /** @return array<string, mixed> */
    private function baseData(): array
    {
        return [
            'app_name' => 'Test', 'app_url' => 'https://example.test',
            'timezone' => 'Asia/Yekaterinburg', 'locale' => 'ru', 'region' => 'Сургут', 'currency' => 'RUB',
            'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'nabilet',
            'db_username' => 'nabilet', 'db_password' => 'secret',
            'admin_email' => 'owner@example.test', 'admin_password' => 'long-enough-password',
        ];
    }
}
