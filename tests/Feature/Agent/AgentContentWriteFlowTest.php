<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Двухфазный контентный поток: подготовил → одобрил → исполнил.
 *
 * Проверяет, что подготовка не трогает бизнес-данные, что токен без
 * agent:content:write не может подготовить изменение, а одобренный снимок
 * исполняется только один раз и остаётся в границах организации.
 */
class AgentContentWriteFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_stays_draft_until_human_approves_and_confirm_is_idempotent(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();
        $headers = $this->tokenHeaders($user, [
            AgentCapabilities::READ,
            AgentCapabilities::CONTENT_WRITE,
            AgentCapabilities::ACTIONS_CONFIRM,
        ]);

        $prep = $this->mcp('tools/call', [
            'name' => 'prepare_create_page',
            'arguments' => ['title' => 'Страница от агента', 'content' => '<p>Текст</p>'],
        ], $headers);
        $prep->assertOk()->assertJsonPath('result.isError', false);
        $publicId = $prep->json('result.structuredContent.action_public_id');
        $this->assertNotEmpty($publicId);
        $this->assertSame(0, DB::table('pages')->count(), 'подготовка не создаёт страницу');
        $snapshot = json_decode((string) DB::table('agent_actions')->where('public_id', $publicId)->value('payload_json'), true);
        $this->assertSame('draft', $snapshot['status'] ?? null);

        $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers)->assertOk()->assertJsonPath('result.isError', true);

        $this->approve($user, $publicId);

        $exec = $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers);
        $exec->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame('draft', DB::table('pages')->value('status'));

        $again = $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers);
        $again->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame(
            $exec->json('result.structuredContent.page_public_id'),
            $again->json('result.structuredContent.page_public_id'),
        );
    }

    public function test_token_without_content_write_cannot_prepare(): void
    {
        [, $user] = $this->seedStaffWithOrg();
        $headers = $this->tokenHeaders($user, [AgentCapabilities::READ]);

        $this->mcp('tools/call', [
            'name' => 'prepare_create_page',
            'arguments' => ['title' => 'Нет прав'],
        ], $headers)->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame(0, DB::table('agent_actions')->count());
    }

    public function test_update_seo_is_scoped_to_the_token_organization(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();
        $headers = $this->tokenHeaders($user, [
            AgentCapabilities::READ, AgentCapabilities::CONTENT_WRITE, AgentCapabilities::ACTIONS_CONFIRM,
        ]);
        $pageId = $this->seedPage($orgId);

        $prep = $this->mcp('tools/call', [
            'name' => 'prepare_update_seo',
            'arguments' => [
                'entity_type' => 'page', 'entity_id' => $pageId, 'locale' => 'ru',
                'title' => 'SEO от агента', 'description' => 'Описание',
            ],
        ], $headers);
        $prep->assertOk()->assertJsonPath('result.isError', false);
        $publicId = $prep->json('result.structuredContent.action_public_id');
        $this->assertSame(0, DB::table('seo_meta')->count());

        $this->approve($user, $publicId);
        $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers)->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame('SEO от агента', DB::table('seo_meta')->where('entity_id', $pageId)->value('title'));

        $foreignOrg = $this->seedOrg();
        $foreignPage = $this->seedPage($foreignOrg);
        $this->mcp('tools/call', [
            'name' => 'prepare_update_seo',
            'arguments' => ['entity_type' => 'page', 'entity_id' => $foreignPage, 'title' => 'Чужое'],
        ], $headers)->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_update_theme_merges_without_touching_other_settings(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();
        $headers = $this->tokenHeaders($user, [
            AgentCapabilities::READ, AgentCapabilities::CONTENT_WRITE, AgentCapabilities::ACTIONS_CONFIRM,
        ]);
        DB::table('storefront_settings')->insert([
            'organization_id' => $orgId,
            'config' => json_encode(['support' => ['email' => 'keep@example.test'], 'theme' => ['primary' => '#000000']]),
        ]);

        $prep = $this->mcp('tools/call', [
            'name' => 'prepare_update_theme',
            'arguments' => ['config' => ['theme' => ['primary' => '#123456']]],
        ], $headers);
        $prep->assertOk()->assertJsonPath('result.isError', false);
        $publicId = $prep->json('result.structuredContent.action_public_id');
        $this->approve($user, $publicId);
        $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers)->assertOk()->assertJsonPath('result.isError', false);

        $config = json_decode((string) DB::table('storefront_settings')->where('organization_id', $orgId)->value('config'), true);
        $this->assertSame('#123456', $config['theme']['primary']);
        $this->assertSame('keep@example.test', $config['support']['email'], 'посторонние ключи не затронуты');

        $this->mcp('tools/call', [
            'name' => 'prepare_update_theme',
            'arguments' => ['config' => ['secret_key' => 'bad']],
        ], $headers)->assertOk()->assertJsonPath('result.isError', true);
    }

    /** @param array<int, string> $abilities @return array<string, string> */
    private function tokenHeaders(User $user, array $abilities): array
    {
        $token = $user->createToken('content-agent', $abilities)->plainTextToken;

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json, text/event-stream'];
    }

    private function mcp(string $method, array $params, array $headers): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => random_int(100, 999),
            'method' => $method,
            'params' => $params,
        ], $headers);
    }

    private function approve(User $user, string $publicId): void
    {
        $id = DB::table('agent_actions')->where('public_id', $publicId)->value('id');
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/admin/agent/actions/' . (int) $id . '/approve', [])
            ->assertOk();
    }

    /** @return array{0: int, 1: User} */
    private function seedStaffWithOrg(): array
    {
        $orgId = $this->seedOrg();
        $now = now()->toDateTimeString();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'content-write-' . Str::random(6) . '@example.test',
            'first_name' => 'Content',
            'last_name' => 'Writer',
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

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        return [$orgId, $user];
    }

    private function seedOrg(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org ' . Str::random(5),
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPage(int $orgId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('pages')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'title' => 'Страница',
            'slug' => 'page-' . Str::random(6),
            'content' => 'Текст',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
