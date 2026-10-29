<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Ручное скачивание локальных бэкапов (админка).
 *
 * Проверяет:
 *   - аноним и не-admin-сотрудник не видят список;
 *   - admin видит список и скачивает файл;
 *   - имя файла строго валидируется — обход пути невозможен;
 *   - несуществующий файл — 404.
 */
final class AdminBackupDownloadTest extends TestCase
{
    use RefreshDatabase;
    use StaffAuth;

    private function seedBackupFile(string $name, string $contents = 'dump'): void
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $name, $contents);
    }

    private function organization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org',
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_anonymous_is_rejected(): void
    {
        $this->seedBackupFile('db_test_2026-10-30.sql');

        $this->getJson('/api/v1/admin/backups')->assertStatus(401);
    }

    public function test_non_admin_staff_is_rejected(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::query()->findOrFail($userId), 'api');

        $this->getJson('/api/v1/admin/backups')->assertStatus(403);
    }

    public function test_admin_can_list_and_download_a_backup(): void
    {
        $this->seedBackupFile('db_test_2026-10-30.sql', 'dump-content');
        $this->actingAsStaff($this->organization());

        $list = $this->getJson('/api/v1/admin/backups')->assertOk();

        $names = collect($list->json('data'))->pluck('name')->all();
        $this->assertContains('db_test_2026-10-30.sql', $names);

        $download = $this->get('/api/v1/admin/backups/db_test_2026-10-30.sql')
            ->assertOk()
            ->assertHeaderContains('Content-Disposition', 'attachment; filename=');

        $this->assertSame('dump-content', $download->streamedContent());
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->seedBackupFile('legit.sql');

        $this->getJson('/api/v1/admin/backups/..%2F..%2F.env')->assertStatus(404);
        $this->getJson('/api/v1/admin/backups/..%2F.env')->assertStatus(404);
        $this->getJson('/api/v1/admin/backups/legit%2Esql%23')->assertStatus(404);
    }
}
