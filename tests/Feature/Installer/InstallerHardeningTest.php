<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Database\Seeders\NabiletAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Installer\Http\Controllers\InstallerController;
use Tests\TestCase;

/**
 * Аудит готовности к установке (AUDIT-INSTALLER-READINESS-2026-10-10).
 *
 * Фиксируем блокеры B-1..B-4 и дефекты W-2/W-3:
 *  - B-1: мастер требует PHP ≥ 8.3 (composer.json: ^8.3), а не 8.1;
 *  - B-2: `db:seed --force` НЕ создаёт демо-учётки с паролями из исходников
 *         на боевом стенде, но роли и права сидируются всегда;
 *  - B-3: сгенерированный `.env` использует `QUEUE_CONNECTION=database`,
 *         `CACHE_STORE` (а не мёртвый `CACHE_DRIVER`) и `SESSION_DRIVER=database`;
 *  - B-4: `.env` содержит полный блок `MAIL_*`;
 *  - W-2: POST-эндпоинты установщика ограничены по частоте (гонка установки);
 *  - W-3: «Проверить подключение к БД» — реальная серверная проверка, не имитация.
 */
class InstallerHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function invoke(string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod(InstallerController::class, $method))
            ->invokeArgs(new InstallerController(), $args);
    }

    /** @return array<string, mixed> */
    private function baseData(): array
    {
        return [
            'app_name' => 'My Tickets',
            'app_url' => 'https://tickets.example.com',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'nabilet',
            'db_username' => 'nabilet',
            'db_password' => 'secret',
            'admin_email' => 'owner@example.com',
            'admin_password' => 'super-secret-password',
        ];
    }

    /* ── B-3: рабочие драйверы очереди / кеша / сессий ─────────────────────── */

    public function test_generated_env_uses_working_queue_cache_and_session_drivers(): void
    {
        $env = $this->invoke('buildEnvContent', [$this->baseData(), 'base64:AAAA']);

        // Письма и вебхуки уходят в очередь, а не в HTTP-запрос покупателя.
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $env);
        $this->assertStringNotContainsString('QUEUE_CONNECTION=sync', $env);

        // config/cache.php читает CACHE_STORE; CACHE_DRIVER молча игнорировался.
        $this->assertStringContainsString('CACHE_STORE=database', $env);
        $this->assertStringNotContainsString('CACHE_DRIVER', $env);

        $this->assertStringContainsString('SESSION_DRIVER=database', $env);
        $this->assertStringContainsString('BROADCAST_CONNECTION=log', $env);

        // Ни один плейсхолдер не должен остаться незамещённым.
        $this->assertStringNotContainsString('{', $env);
    }

    /* ── B-4: полный блок MAIL_* ───────────────────────────────────────────── */

    public function test_generated_env_contains_a_full_mail_block(): void
    {
        $env = $this->invoke('buildEnvContent', [$this->baseData(), 'base64:AAAA']);

        foreach ([
            'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME',
            'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        ] as $key) {
            $this->assertStringContainsString($key . '=', $env, "В .env нет {$key}");
        }

        // Без SMTP письма пишутся в журнал — установка не падает и не «тихо
        // ломается» на первой же отправке.
        $this->assertStringContainsString('MAIL_MAILER=log', $env);
        // Адрес/имя отправителя по умолчанию берутся из данных мастера.
        $this->assertStringContainsString('MAIL_FROM_ADDRESS=owner@example.com', $env);
        $this->assertStringContainsString('MAIL_FROM_NAME="My Tickets"', $env);
    }

    public function test_generated_env_switches_to_smtp_when_host_is_provided(): void
    {
        $data = $this->baseData() + [
            'mail_host' => 'smtp.yandex.ru',
            'mail_port' => 465,
            'mail_username' => 'noreply@example.com',
            'mail_password' => 'p@ss',
            'mail_encryption' => 'SSL',
            'mail_from_address' => 'tickets@example.com',
            'mail_from_name' => 'Tickets',
        ];

        $env = $this->invoke('buildEnvContent', [$data, 'base64:AAAA']);

        $this->assertStringContainsString('MAIL_MAILER=smtp', $env);
        $this->assertStringContainsString('MAIL_HOST=smtp.yandex.ru', $env);
        $this->assertStringContainsString('MAIL_PORT=465', $env);
        $this->assertStringContainsString('MAIL_ENCRYPTION=ssl', $env);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS=tickets@example.com', $env);
    }

    public function test_env_value_quotes_and_escapes_unsafe_passwords(): void
    {
        // Пароль с пробелом и кавычкой раньше разрывал .env (аудит §7).
        $this->assertSame('"pa ss\\"word#1"', $this->invoke('envValue', ['pa ss"word#1']));

        // Простые значения остаются без кавычек.
        $this->assertSame('nabilet', $this->invoke('envValue', ['nabilet']));
        $this->assertSame('', $this->invoke('envValue', ['']));
    }

    /* ── B-1: требование PHP 8.3 ──────────────────────────────────────────── */

    public function test_php_requirement_is_83(): void
    {
        $requirements = $this->invoke('checkRequirements');

        $this->assertSame('8.3', $requirements['details']['php_version']['required']);
    }

    public function test_project_root_is_checked_for_writability(): void
    {
        $requirements = $this->invoke('checkRequirements');

        // Именно в корень проекта пишется `.env` (writeEnvFile).
        $this->assertArrayHasKey('writable_' . md5(base_path()), $requirements['details']);
    }

    /* ── B-2: демо-учётки не создаются в production ───────────────────────── */

    public function test_installer_seeding_skips_demo_accounts_in_production_but_keeps_rbac(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--class' => NabiletAdminSeeder::class, '--force' => true]);

        $this->assertSame(0, DB::table('users')->whereIn('email', [
            'admin@nabilet.local',
            'manager@nabilet.local',
            'support@nabilet.local',
        ])->count(), 'Демо-учётки с паролями из исходников не должны появляться в production');

        // Роли и права нужны всегда — иначе RBAC не работает.
        $this->assertTrue(DB::table('roles')->where('slug', 'admin')->exists());
        $this->assertGreaterThan(0, DB::table('permissions')->count());
        $this->assertGreaterThan(0, DB::table('role_permissions')->count());
    }

    public function test_demo_accounts_are_still_seeded_outside_production(): void
    {
        $this->artisan('db:seed', ['--class' => NabiletAdminSeeder::class, '--force' => true]);

        $this->assertTrue(DB::table('users')->where('email', 'admin@nabilet.local')->exists());
    }

    public function test_demo_accounts_can_be_forced_in_production_by_flag(): void
    {
        $this->app['env'] = 'production';
        config(['nabilet.seed_demo' => true]);

        $this->artisan('db:seed', ['--class' => NabiletAdminSeeder::class, '--force' => true]);

        $this->assertTrue(DB::table('users')->where('email', 'admin@nabilet.local')->exists());
    }

    /* ── W-2: throttle против гонки установки ─────────────────────────────── */

    public function test_install_post_routes_are_rate_limited(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $install = $routes->first(fn ($r) => $r->getName() === 'installer.install');
        $testDb = $routes->first(fn ($r) => $r->getName() === 'installer.test-database');

        $this->assertNotNull($install);
        $this->assertNotNull($testDb);
        $this->assertContains('throttle:5,1', $install->gatherMiddleware());
        $this->assertContains('throttle:20,1', $testDb->gatherMiddleware());
    }

    /* ── W-3: реальная проверка подключения к БД ──────────────────────────── */

    public function test_test_database_endpoint_connects_with_valid_credentials(): void
    {
        $connection = config('database.connections.mysql');

        $this->postJson('/install/test-database', [
            'db_host' => $connection['host'],
            'db_port' => (int) $connection['port'],
            'db_database' => $connection['database'],
            'db_username' => $connection['username'],
            'db_password' => $connection['password'],
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_test_database_endpoint_reports_bad_credentials(): void
    {
        $this->postJson('/install/test-database', [
            'db_host' => '127.0.0.1',
            'db_port' => 3307,
            'db_database' => 'nabilet_test_suite',
            'db_username' => 'root',
            'db_password' => 'definitely-not-the-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'DB_CONNECTION_FAILED');
    }
}
