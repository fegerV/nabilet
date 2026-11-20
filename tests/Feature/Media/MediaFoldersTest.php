<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Media\Models\MediaAsset;
use Nabilet\Modules\Media\Models\MediaFolder;
use Tests\TestCase;

/**
 * Виртуальные папки медиатеки и фильтры списка.
 *
 * Проверяется через HTTP: маршруты, права и то, что папка НЕ меняет путь файла
 * на диске (иначе перемещение ломало бы ссылки афиш и галерей).
 */
class MediaFoldersTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->organizationId = $this->seedOrganization();
    }

    public function test_folder_crud_and_file_move(): void
    {
        $this->actingAsAdmin();

        $created = $this->postJson('/api/v1/media/folders', ['name' => 'Афиши'])
            ->assertCreated()
            ->json('data');
        $folderId = (int) $created['id'];

        $this->assertSame('Афиши', $created['name']);
        $this->assertSame(0, $created['parent_id']);

        // Повтор того же имени в той же папке — 422, а не вторая папка.
        $this->postJson('/api/v1/media/folders', ['name' => 'Афиши'])->assertStatus(422);

        $upload = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('poster.jpg', 400, 400),
            'folder_id' => $folderId,
        ])->assertCreated()->json('data');
        $mediaId = (int) $upload['id'];

        $this->assertSame($folderId, $upload['folder_id']);
        $this->assertStringStartsWith('media/', $upload['path'], 'папка не меняет путь на диске');

        $this->getJson('/api/v1/media?folder_id='.$folderId)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/media')->assertOk()->assertJsonPath('meta.total', 1);

        // Перемещение в корень.
        $this->postJson('/api/v1/media/folders/move', [
            'media_ids' => [$mediaId],
            'folder_id' => 0,
        ])->assertOk()->assertJsonPath('data.moved', 1);

        $this->assertNull(MediaAsset::query()->findOrFail($mediaId)->folder_id);
        $this->assertSame('media/'.basename($upload['path']), $upload['path']);

        // Переименование и защита непустой папки от удаления.
        $this->patchJson('/api/v1/media/folders/'.$folderId, ['name' => 'Афиши 2026'])->assertOk();
        $folder = MediaFolder::query()->findOrFail($folderId);
        $this->assertSame('Афиши 2026', $folder->name);

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('second.jpg', 400, 400),
            'folder_id' => $folderId,
        ])->assertCreated();

        $this->deleteJson('/api/v1/media/folders/'.$folderId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_list_filters_by_search_and_kind(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('Концерт-афиша.jpg', 400, 400),
        ])->assertCreated();
        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('другое.png', 200, 200),
        ])->assertCreated();

        $this->getJson('/api/v1/media?search='.urlencode('афиша'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/media?kind=image')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/media?kind=video')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function actingAsAdmin(): array
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

        return [$userId, $this->organizationId, $roleId];
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Тестовая организация',
            'slug' => 'test-org-'.Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
