<?php

declare(strict_types=1);

namespace Tests\Feature\Core\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Доступ к админским операциям над пользователями.
 *
 * `app/Modules/Core/Users/routes/api.php` вешал только `auth:api` — без
 * `admin` (`EnsureAdminRole`). Остальные модули (`Events`, `Orders`, `Venues`,
 * `Tickets`, `Pricing`, `Webhooks`, `System`, …) применяют `admin` на write-роуты,
 * поэтому «забытый» middleware здесь не виден ни в одном диффе: файл выглядит
 * так же правдоподобно, как соседние.
 *
 * Последствие — не просто чтение чужого списка пользователей. `POST
 * /users/{publicId}/roles` позволяет назначить роль самому себе, то есть любой
 * аутентифицированный покупатель повышал себя до администратора.
 */
class UserAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_buyer_cannot_list_users(): void
    {
        $this->actingAs($this->plainUser('attacker@example.com'), 'api');

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_a_plain_buyer_cannot_read_an_arbitrary_user(): void
    {
        $victim = $this->plainUser('victim@example.com');
        $this->actingAs($this->plainUser('attacker@example.com'), 'api');

        $this->getJson('/api/v1/users/' . $victim->public_id)->assertForbidden();
    }

    public function test_a_plain_buyer_cannot_create_users(): void
    {
        $this->actingAs($this->plainUser(), 'api');

        $this->postJson('/api/v1/users', [
            'email' => 'backdoor@example.com',
            'password' => 'password123',
        ])->assertForbidden();

        $this->assertFalse(
            DB::table('users')->where('email', 'backdoor@example.com')->exists(),
            'Обычный пользователь не должен уметь создавать учётные записи'
        );
    }

    public function test_a_plain_buyer_cannot_change_another_user(): void
    {
        $victim = $this->plainUser('victim@example.com');
        $this->actingAs($this->plainUser(), 'api');

        $this->putJson('/api/v1/users/' . $victim->public_id, [
            'password' => 'hijacked-password',
        ])->assertForbidden();
    }

    public function test_a_plain_buyer_cannot_delete_users(): void
    {
        $victim = $this->plainUser('victim@example.com');
        $this->actingAs($this->plainUser(), 'api');

        $this->deleteJson('/api/v1/users/' . $victim->public_id)->assertForbidden();

        $this->assertTrue(DB::table('users')->where('id', $victim->id)->exists());
    }

    /**
     * Главная эскалация: покупатель выдаёт себе роль администратора.
     */
    public function test_a_plain_buyer_cannot_assign_roles(): void
    {
        $attacker = $this->plainUser('attacker@example.com');
        $adminRoleId = $this->adminRoleId();

        $this->actingAs($attacker, 'api');

        $this->postJson("/api/v1/users/{$attacker->public_id}/roles", [
            'role_id' => $adminRoleId,
        ])->assertForbidden();

        $this->assertSame(
            0,
            DB::table('user_roles')->where('user_id', $attacker->id)->count(),
            'Покупатель не должен уметь выдать себе роль администратора'
        );
    }

    public function test_a_plain_buyer_cannot_remove_roles(): void
    {
        $victim = $this->plainUser('victim@example.com');
        $this->actingAs($this->plainUser(), 'api');

        $this->deleteJson("/api/v1/users/{$victim->public_id}/roles/" . $this->adminRoleId())
            ->assertForbidden();
    }

    public function test_staff_can_still_list_users(): void
    {
        $this->actingAs($this->staffUser(), 'api');

        $this->getJson('/api/v1/users')->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    public function test_staff_can_still_assign_roles(): void
    {
        $target = $this->plainUser('target@example.com');
        $this->actingAs($this->staffUser(), 'api');

        $this->postJson("/api/v1/users/{$target->public_id}/roles", [
            'role_id' => $this->adminRoleId(),
        ])->assertOk();

        $this->assertGreaterThan(0, DB::table('user_roles')->where('user_id', $target->id)->count());
    }

    /* ── фикстуры ─────────────────────────────────────────────────────────── */

    private function plainUser(string $email = 'buyer@example.com'): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * Сотрудник: `StaffRole::isStaff()` читает `user_roles`, а не колонку в
     * `users`. Организация нужна только как обязательный ключ членства.
     */
    private function staffUser(): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Staff Org',
            'slug' => 'staff-org',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = $this->plainUser('staff@example.com');

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $this->adminRoleId(),
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $this->adminRoleId(),
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($user->id);
    }

    private function adminRoleId(): int
    {
        $existing = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('roles')->insertGetId([
            'name' => 'Администратор',
            'slug' => 'admin',
        ]);
    }
}
