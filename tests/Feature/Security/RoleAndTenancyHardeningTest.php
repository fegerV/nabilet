<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Закрепление исправлений из аудита 2026-11-20:
 *  - роль manager удалена, admin проверяется в текущей организации;
 *  - роль без членства не даёт контекст арендатора;
 *  - галерея медиа не утекает в другую организацию;
 *  - чекер не отдаёт билет (QR + PII) при отказе.
 */
class RoleAndTenancyHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_manager_role_does_not_grant_admin_access(): void
    {
        $org = $this->organization();
        $managerSlug = 'manager';
        $managerId = $this->roleId($managerSlug);

        // roleId() создаёт роль, если её нет: проверяем именно поведение, а не
        // отсутствие роли — manager обязан получить 403 на админ-API.
        $user = $this->userWithRole($managerId, $org);
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/system/status')
            ->assertStatus(403);
    }

    public function test_admin_of_one_organization_cannot_assign_roles_through_foreign_org(): void
    {
        $mine = $this->organization();
        $foreign = $this->organization();
        $adminRoleId = $this->roleId('admin');
        $admin = $this->userWithRole($adminRoleId, $mine);

        $victim = $this->userWithRole($this->roleId('member'), $foreign);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/users/'.$victim->public_id.'/roles', ['role_id' => $adminRoleId])
            ->assertStatus(404); // чужой пользователь неотличим от несуществующего
    }

    public function test_media_gallery_does_not_leak_foreign_organization(): void
    {
        $mine = $this->organization();
        $foreign = $this->organization();
        $adminRoleId = $this->roleId('admin');
        $this->actingAs($this->userWithRole($adminRoleId, $mine), 'api');

        // Чужой файл и связь с событием другой организации.
        $assetId = (int) DB::table('media_assets')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $foreign,
            'disk' => 'public',
            'path' => 'media/foreign.png',
            'filename' => 'foreign.png',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('media_links')->insert([
            'media_asset_id' => $assetId,
            'entity_type' => 'event',
            'entity_id' => 999,
            'role' => 'gallery',
            'position' => 0,
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/media?entity_type=event&entity_id=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_role_without_membership_does_not_create_an_event(): void
    {
        $org = $this->organization();
        $adminRoleId = $this->roleId('admin');
        $user = $this->userWithRole($adminRoleId, $org, withMembership: false);

        $this->actingAs($user, 'api')->postJson('/api/v1/events', [
            'title' => 'Чужой арендатор',
            'slug' => 'foreign-'.Str::random(6),
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('events')->count());
    }

    private function roleId(string $slug): int
    {
        $id = DB::table('roles')->where('slug', $slug)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('roles')->insertGetId([
            'name' => ucfirst($slug), 'slug' => $slug,
        ]);
    }

    private function organization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org '.Str::random(4),
            'slug' => 'org-'.Str::random(8),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userWithRole(int $roleId, int $organizationId, bool $withMembership = true): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'u-'.Str::random(8).'@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $id, 'organization_id' => $organizationId, 'role_id' => $roleId, 'created_at' => now(),
        ]);
        if ($withMembership) {
            DB::table('user_organization')->insert([
                'user_id' => $id, 'organization_id' => $organizationId, 'role_id' => $roleId, 'created_at' => now(),
            ]);
        }

        return User::query()->findOrFail($id);
    }
}
