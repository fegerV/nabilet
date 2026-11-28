<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Tests\TestCase;

/**
 * Разделение полномочий MCP: agent:read (бизнес своей организации),
 * agent:system:read (глобальная диагностика только системного администратора),
 * agent:content:read/write. Две независимые организации и системный администратор.
 */
final class AgentPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nabilet.platform_admins' => ['sysadmin@example.test'], 'nabilet.single_tenant_admin' => false]);
    }

    public function test_business_token_sees_only_business_tools(): void
    {
        $owner = $this->staff('org-a@example.test');
        $headers = $this->headers($owner, [AgentCapabilities::READ]);

        $list = $this->mcp('tools/list', [], $headers)->assertOk()->json('result.tools');
        $names = array_column($list, 'name');

        $this->assertContains('sales_summary', $names);
        $this->assertContains('get_order', $names);
        $this->assertNotContains('get_system_status', $names);
        $this->assertNotContains('search_pages', $names);
        $this->assertNotContains('prepare_create_page', $names);
    }

    public function test_global_diagnostics_requires_system_capability(): void
    {
        $owner = $this->staff('org-b@example.test');
        $this->mcp('tools/call', ['name' => 'get_system_status', 'arguments' => []],
            $this->headers($owner, [AgentCapabilities::READ]))
            ->assertStatus(400);

        $sys = $this->staff('sysadmin@example.test');
        $response = $this->mcp('tools/call', ['name' => 'get_system_status', 'arguments' => []],
            $this->headers($sys, [AgentCapabilities::READ, AgentCapabilities::SYSTEM_READ]));
        $response->assertOk()->assertJsonPath('result.isError', false);
        $response->assertJsonPath('result.structuredContent.section', 'systemStatus');
    }

    public function test_system_capability_alone_does_not_grant_business_or_content_data(): void
    {
        $sys = $this->staff('sysadmin@example.test');
        $headers = $this->headers($sys, [AgentCapabilities::SYSTEM_READ]);

        $list = $this->mcp('tools/list', [], $headers)->assertOk()->json('result.tools');
        $names = array_column($list, 'name');

        $this->assertContains('get_system_status', $names);
        $this->assertNotContains('sales_summary', $names);
        $this->assertNotContains('search_pages', $names);
    }

    public function test_content_read_is_a_separate_capability(): void
    {
        $owner = $this->staff('org-a@example.test');
        $this->mcp('tools/call', ['name' => 'search_pages', 'arguments' => ['query' => 'концерт']],
            $this->headers($owner, [AgentCapabilities::READ]))
            ->assertStatus(400);

        $this->mcp('tools/call', ['name' => 'search_pages', 'arguments' => ['query' => 'концерт']],
            $this->headers($owner, [AgentCapabilities::CONTENT_READ]))
            ->assertOk()->assertJsonPath('result.isError', false);
    }

    public function test_org_staff_cannot_read_another_organizations_orders(): void
    {
        $this->staff('org-a@example.test');
        $this->staff('org-b@example.test');
        $orgB = (int) DB::table('organizations')->where('name', 'Org B')->value('id');
        $foreignOrder = $this->order($orgB);

        $ownerA = $this->staff('org-a@example.test');
        $this->mcp('tools/call', ['name' => 'get_order', 'arguments' => ['public_id' => $foreignOrder]],
            $this->headers($ownerA, [AgentCapabilities::READ]))
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_universal_wildcard_token_cannot_reach_global_diagnostics(): void
    {
        // Sanctum считает '*' универсальным ability и отдаёт tokenCan() == true
        // для любого полномочия. Такой токен не проходит агентный гейт вообще.
        $sys = $this->staff('sysadmin@example.test');
        $headers = $this->headers($sys, ['*']);

        $this->mcp('tools/list', [], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->mcp('tools/call', ['name' => 'get_system_status', 'arguments' => []], $headers)
            ->assertStatus(403);
    }

    public function test_org_admin_cannot_issue_a_system_read_token(): void
    {
        $actor = $this->staff('org-a@example.test');

        $this->actingAs($actor, 'api')
            ->postJson('/api/v1/admin/agent/tokens', [
                'name' => 'privileged',
                'abilities' => [AgentCapabilities::READ, AgentCapabilities::SYSTEM_READ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertSame(0, (int) DB::table('personal_access_tokens')
            ->where('name', 'privileged')->count());
    }

    public function test_platform_admin_can_issue_system_read_token_after_clean_install(): void
    {
        // Мастер 1.16.3 записывает владельца в NABILET_PLATFORM_ADMINS — ровно
        // это состояние и воспроизводит config() в setUp().
        $owner = $this->staff('sysadmin@example.test');
        $response = $this->actingAs($owner, 'api')
            ->postJson('/api/v1/admin/agent/tokens', [
                'name' => 'diagnostics',
                'abilities' => [AgentCapabilities::READ, AgentCapabilities::SYSTEM_READ],
            ]);
        $response->assertStatus(201)
            ->assertJsonPath('data.abilities', [AgentCapabilities::READ, AgentCapabilities::SYSTEM_READ]);
        $token = (string) $response->json('data.token');
        $this->assertNotSame('', $token);

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 501, 'method' => 'tools/call',
            'params' => ['name' => 'get_system_status', 'arguments' => new \stdClass()],
        ], ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.section', 'systemStatus');
    }

    public function test_system_read_cannot_be_issued_for_a_foreign_role(): void
    {
        $sys = $this->staff('sysadmin@example.test');
        $this->staff('org-a@example.test');

        $this->actingAs($sys, 'api')
            ->postJson('/api/v1/admin/agent/tokens', [
                'name' => 'for-other',
                'email' => 'org-a@example.test',
                'abilities' => [AgentCapabilities::READ, AgentCapabilities::SYSTEM_READ],
            ])
            ->assertStatus(422);
        $this->assertSame(0, (int) DB::table('personal_access_tokens')
            ->where('name', 'for-other')->count());
    }

    /** @param list<string> $abilities @return array<string, string> */
    private function headers(\Nabilet\Modules\Core\Users\Models\User $user, array $abilities): array
    {
        $token = $user->createToken('split-test', $abilities)->plainTextToken;

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json, text/event-stream'];
    }

    private function mcp(string $method, array $params, array $headers): \Illuminate\Testing\TestResponse
    {
        // Тестовое приложение переиспользуется между запросами; Sanctum кеширует
        // пользователя в guard, поэтому сбрасываем его перед следующим токеном.
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => random_int(100, 999), 'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ], $headers);
    }

    private function staff(string $email): \Nabilet\Modules\Core\Users\Models\User
    {
        $existing = \Nabilet\Modules\Core\Users\Models\User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }
        $now = now()->toDateTimeString();
        $orgName = str_contains($email, 'org-a') ? 'Org A' : (str_contains($email, 'org-b') ? 'Org B' : 'Platform');
        $org = (int) DB::table('organizations')->where('name', $orgName)->value('id');
        if ($org === 0) {
            $org = (int) DB::table('organizations')->insertGetId([
                'public_id' => (string) Str::ulid()->toBase32(), 'name' => $orgName,
                'slug' => 'split-' . Str::lower(Str::random(6)), 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(), 'email' => $email,
            'first_name' => 'Split', 'last_name' => 'Test', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $org, 'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $org, 'role_id' => $roleId, 'created_at' => $now,
        ]);

        /** @var \Nabilet\Modules\Core\Users\Models\User $user */
        $user = \Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId);

        return $user;
    }

    private function order(int $orgId): string
    {
        $publicId = (string) Str::ulid()->toBase32();
        $now = now()->toDateTimeString();
        DB::table('orders')->insert([
            'public_id' => $publicId, 'organization_id' => $orgId, 'order_number' => 'SPLIT-' . Str::random(6),
            'status' => 'paid', 'payment_status' => 'succeeded', 'subtotal_amount' => 100,
            'discount_amount' => 0, 'bonus_amount' => 0, 'fee_amount' => 0, 'total_amount' => 100,
            'currency' => 'RUB', 'customer_email' => 'foreign@example.test',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $publicId;
    }
}
