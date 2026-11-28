<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Управление токенами ИИ-агента (`nabilet:agent-token`).
 *
 * Регрессия: `agentTokens()` объявлял Eloquent\Builder, а DB::table() возвращал
 * Query\Builder — команды `--list`/`--revoke-all` падали TypeError, то есть
 * отозвать утекший токен было невозможно. Вторая регрессия: `--revoke-all`
 * отзывал старые токены и тут же выпускал новый, хотя документация обещала
 * полный отзыв — теперь это `--rotate`, а `--revoke-all` ничего не выпускает.
 */
class AgentTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'agent-command@example.test';

    public function test_list_revoke_all_and_rotate_work(): void
    {
        $user = $this->seedStaffUser();

        $this->artisan('nabilet:agent-token', ['email' => self::EMAIL, '--list' => true])
            ->assertExitCode(0);

        $this->artisan('nabilet:agent-token', ['email' => self::EMAIL, '--name' => 'first'])
            ->assertExitCode(0);
        $this->assertSame(1, $this->tokenCount($user));

        $this->artisan('nabilet:agent-token', ['email' => self::EMAIL, '--revoke-all' => true])
            ->assertExitCode(0);
        $this->assertSame(0, $this->tokenCount($user), '--revoke-all не должен выпускать новый токен');

        $this->artisan('nabilet:agent-token', ['email' => self::EMAIL, '--rotate' => true])
            ->assertExitCode(0);
        $this->assertSame(1, $this->tokenCount($user), '--rotate выдаёт ровно один новый токен');
    }

    public function test_non_staff_user_cannot_get_an_agent_token(): void
    {
        DB::table('users')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'buyer-agent@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('nabilet:agent-token', ['email' => 'buyer-agent@example.test'])
            ->assertExitCode(1);
    }

    public function test_system_read_is_refused_for_an_ordinary_org_admin(): void
    {
        $user = $this->seedStaffUser();
        config(['nabilet.platform_admins' => [], 'nabilet.single_tenant_admin' => false]);

        $this->artisan('nabilet:agent-token', [
            'email' => self::EMAIL,
            '--abilities' => 'agent:read,agent:system:read',
        ])->assertExitCode(1);
        $this->assertSame(0, $this->tokenCount($user));
    }

    public function test_system_read_is_issued_to_a_platform_admin(): void
    {
        $user = $this->seedStaffUser();
        config(['nabilet.platform_admins' => [self::EMAIL], 'nabilet.single_tenant_admin' => false]);

        $this->artisan('nabilet:agent-token', [
            'email' => self::EMAIL,
            '--abilities' => 'agent:read,agent:system:read',
        ])->assertExitCode(0);
        $this->assertSame(1, $this->tokenCount($user));
    }

    private function tokenCount(object $user): int
    {
        return (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', $user::class)
            ->where('tokenable_id', $user->id)
            ->count();
    }

    private function seedStaffUser(): object
    {
        $now = now()->toDateTimeString();
        $orgId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org',
            'slug' => 'org-'.Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => self::EMAIL,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $orgId, 'role_id' => $roleId, 'created_at' => $now,
        ]);

        return \Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId);
    }
}
