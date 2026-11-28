<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nabilet\Modules\Installer\Support\InstallKey;
use Tests\TestCase;

/**
 * Защита первоначальной установки: одноразовый ключ из storage/install.key,
 * отказ постороннему посетителю, блокировкапараллельного запуска и CSRF.
 */
final class InstallerKeySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        InstallKey::forget();
        @unlink(storage_path('framework/install-in-progress.lock'));
        @unlink(storage_path('framework/install-state.json'));
        $lock = storage_path('install.lock');
        if (is_file($lock)) {
            @unlink($lock);
        }
        parent::tearDown();
    }

    public function test_key_is_created_but_never_disclosed_by_the_wizard(): void
    {
        $response = $this->getJson('/install')->assertOk();

        $this->assertSame('storage/install.key', $response->json('install_key.file'));
        $this->assertFileExists(InstallKey::path());
        $key = trim((string) file_get_contents(InstallKey::path()));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        $this->assertStringNotContainsString($key, (string) $response->getContent());
    }

    public function test_install_rejects_missing_and_wrong_keys(): void
    {
        $payload = $this->payload();

        $this->postJson('/install', $payload)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSTALL_KEY_INVALID');

        $this->postJson('/install', $payload + ['install_key' => str_repeat('0', 64)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSTALL_KEY_INVALID');
    }

    public function test_database_probe_and_log_require_the_key(): void
    {
        $this->postJson('/install/test-database', [
            'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'x',
            'db_username' => 'x', 'db_password' => 'x',
        ])->assertStatus(403)->assertJsonPath('error.code', 'INSTALL_KEY_INVALID');

        $this->getJson('/install/log')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSTALL_KEY_INVALID');
    }

    public function test_parallel_install_is_rejected(): void
    {
        $key = InstallKey::ensure();
        $holder = fopen(storage_path('framework/install-in-progress.lock'), 'c+b');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        try {
            $this->postJson('/install', $this->payload() + ['install_key' => $key])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'INSTALL_IN_PROGRESS');
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    public function test_correct_key_passes_the_guard_and_bad_database_still_fails_later(): void
    {
        $key = InstallKey::ensure();
        $payload = $this->payload() + ['install_key' => $key];

        // Ключ принят: дальше установка доходит до проверки БД и честно падает
        // на недоступных учётках, а не на отказе в авторизации мастера.
        $this->postJson('/install/test-database', $payload)->assertStatus(422)
            ->assertJsonPath('error.code', 'DB_CONNECTION_FAILED');
    }

    public function test_replay_after_install_is_blocked(): void
    {
        $key = InstallKey::ensure();
        file_put_contents(storage_path('install.lock'), now()->toIso8601String());

        $this->getJson('/install')->assertStatus(403)
            ->assertJsonPath('error.code', 'ALREADY_INSTALLED');
        $this->postJson('/install', $this->payload() + ['install_key' => $key])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ALREADY_INSTALLED');
    }

    public function test_reinstall_requires_an_explicit_force_command(): void
    {
        $lock = storage_path('install.lock');
        file_put_contents($lock, now()->toIso8601String());

        $this->artisan('nabilet:install:unlock')->assertFailed();
        $this->assertFileExists($lock);

        $this->artisan('nabilet:install:unlock', ['--force' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($lock);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'app_name' => 'Replay test',
            'app_url' => 'https://tickets.example.test',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'nabilet_missing_db',
            'db_username' => 'nobody',
            'db_password' => 'nothing',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'long-enough-password',
        ];
    }
}
