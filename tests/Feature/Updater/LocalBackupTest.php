<?php

declare(strict_types=1);

namespace Tests\Feature\Updater;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nabilet\Modules\Updater\Jobs\RunLocalBackupJob;
use Nabilet\Modules\Updater\Services\LocalBackupStatus;
use Nabilet\Modules\Updater\Services\UpdatePreflight;
use Nabilet\Modules\Updater\Services\UpdateStorage;
use Tests\TestCase;

/**
 * Локальный бэкап как альтернатива Яндекс.Диску.
 *
 * Проверяется контур решения: при отсутствии Диска обновление НЕ блокируется,
 * а ставит локальный full-бэкап в очередь; готовность определяется по маркеру
 * и реальному файлу, а не по «старому бэкапу когда-то».
 */
final class LocalBackupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdFiles = [];

    /** @var list<int> */
    private array $updateIds = [];

    protected function tearDown(): void
    {
        $statusPath = storage_path('app/updates/local-backup.json');
        @unlink($statusPath);

        foreach ($this->createdFiles as $path) {
            @unlink($path);
        }

        foreach ($this->updateIds as $id) {
            $dir = storage_path('app/updates/' . $id);
            if (is_dir($dir)) {
                $this->deleteTree($dir);
            }
        }

        parent::tearDown();
    }

    public function test_local_backup_is_queued_when_disk_is_not_connected(): void
    {
        Queue::fake();

        $storage = app(UpdateStorage::class);
        $storage->ensureDirectories(0);
        $storage->touchHeartbeat();
        $this->createdFiles[] = $storage->heartbeatFile();

        $contents = 'zip-body-' . \Illuminate\Support\Str::random(10);
        $checksum = hash('sha256', $contents);
        $file = $checksum . '.zip';
        $path = $storage->uploads() . '/' . $file;
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;

        $response = $this->actingAs($this->admin(), 'api')->postJson('/api/v1/admin/system/update/start', [
            'version' => '1.17.0',
            'confirm_phrase' => 'UPDATE',
            'source' => 'upload',
            'file' => $file,
            'checksum' => $checksum,
        ]);

        $response->assertStatus(202);
        $id = (int) $response->json('data.update_id');
        $this->updateIds[] = $id;
        $this->assertGreaterThan(0, $id);

        Queue::assertPushed(RunLocalBackupJob::class, static fn (RunLocalBackupJob $job): bool => $job->updateId === $id);

        $row = \Illuminate\Support\Facades\DB::table('system_updates')->where('id', $id)->first();
        $this->assertSame('queued_local', $row->backup_status, 'Без диска должен ставиться локальный бэкап');
        $this->assertSame('preparing', $row->status, 'Обновление не должно блокироваться из-за отсутствия диска');

        $state = app(LocalBackupStatus::class)->latest();
        $this->assertSame('queued', $state['status'] ?? null);
        $this->assertSame($id, (int) ($state['update_id'] ?? 0));
    }

    public function test_local_backup_status_requires_this_update_and_an_existing_file(): void
    {
        $status = app(LocalBackupStatus::class);
        $startedAt = now()->subMinute();
        $name = 'full_' . date('Y-m-d_H-i-s') . '_localtest.zip';
        $path = storage_path('app/backups/' . $name);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, 'backup');
        $this->createdFiles[] = $path;

        $status->recordSuccess(42, $name);
        $this->assertTrue($status->isSuccessFor(42, $startedAt));
        $this->assertFalse($status->isSuccessFor(43, $startedAt), 'Маркер от другого обновления не подходит');
        $this->assertFalse($status->isSuccessFor(42, now()->addMinute()), 'Бэкап старше обновления не подходит');

        @unlink($path);
        $this->assertFalse($status->isSuccessFor(42, $startedAt), 'Удалённый файл не считается бэкапом');

        $status->recordFailed(42, 'mysqldump died');
        $this->assertTrue($status->isFailedFor(42, $startedAt));
        $this->assertFalse($status->isFailedFor(43, $startedAt));

        $preflight = app(UpdatePreflight::class);
        $this->assertFalse($preflight->hasFreshLocalFullBackup(), 'Удалённый full-архив не должен считаться свежим');
        file_put_contents($path, 'backup');
        $this->assertTrue($preflight->hasFreshLocalFullBackup());
    }

    private function admin(): \Illuminate\Contracts\Auth\Authenticatable
    {
        $organizationId = (int) \Illuminate\Support\Facades\DB::table('organizations')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'local-backup-' . \Illuminate\Support\Str::random(6),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $userId = (int) \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'email' => 'local-backup-' . \Illuminate\Support\Str::random(6) . '@nabilet.local',
            'password' => bcrypt('Secret123456!'),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $roleId = (int) (\Illuminate\Support\Facades\DB::table('roles')->where('slug', 'admin')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
                'description' => 'Полный доступ',
            ]));

        \Illuminate\Support\Facades\DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);
        \Illuminate\Support\Facades\DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);

        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
        $user = \App\Models\User::query()->findOrFail($userId);

        return $user;
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $item->getPathname();
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }
}
