<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nabilet\Modules\Backups\Jobs\RunBackupJob;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Подключение Яндекс.Диска и запуск бэкапов из админки.
 *
 *Главное: токен НЕ возвращается наружу ни в одном ответе, а тяжёлая работа
 * уходит в очередь — контроллер не вызывает mysqldump внутри HTTP-запроса.
 */
class BackupSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin();
    }

    public function test_token_is_saved_encrypted_and_never_exposed(): void
    {
        $response = $this->postJson('/api/v1/admin/backups/yandex/token', [
            'access_token' => 'super-secret-oauth-token',
            'client_id' => '12345',
        ])->assertOk();

        $data = $response->json('data');
        $this->assertTrue($data['connected']);
        $this->assertSame('12345', $data['client_id']);
        $this->assertStringNotContainsString('super-secret-oauth-token', $response->getContent());
        $this->assertArrayNotHasKey('access_token', $data);

        $row = DB::table('settings')
            ->where('scope', 'yandex_disk')
            ->where('setting_key', 'oauth_token')
            ->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->encrypted);
        $this->assertStringNotContainsString('super-secret-oauth-token', (string) $row->value_json);

        $this->postJson('/api/v1/admin/backups/yandex/disconnect', [])->assertOk();
        $this->assertFalse($this->getJson('/api/v1/admin/backups/settings')->json('data.connected'));
    }

    public function test_backup_run_is_queued_with_the_chosen_mode(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/admin/backups/yandex/token', [
            'access_token' => 'token-abc',
        ])->assertOk();

        $this->postJson('/api/v1/admin/backups/run', ['mode' => 'files'])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.mode', 'files');

        Queue::assertPushed(RunBackupJob::class, function (RunBackupJob $job): bool {
            return $job->mode === 'files';
        });

        $status = $this->getJson('/api/v1/admin/backups/settings')->json('data.last_run');
        $this->assertSame('queued', $status['status']);
        $this->assertSame('files', $status['mode']);
    }

    public function test_run_requires_a_connected_disk_and_a_valid_mode(): void
    {
        $this->postJson('/api/v1/admin/backups/run', ['mode' => 'db'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.disk.0', fn ($v) => str_contains((string) $v, 'Диск'));

        $this->postJson('/api/v1/admin/backups/yandex/token', ['access_token' => 'x'])->assertOk();

        $this->postJson('/api/v1/admin/backups/run', ['mode' => 'delete-everything'])
            ->assertStatus(422);
    }

    private function actingAsAdmin(): void
    {
        $now = now();
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $this->organizationId, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $this->organizationId, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        $this->actingAs(User::query()->findOrFail($userId), 'api');
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Тест',
            'slug' => 'org-'.Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
