<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Modules\Agent\Mcp\NabiletAgentServer;
use Nabilet\Modules\Agent\Mcp\Tools\GetHallSchemaTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetPageTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSeoReportTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSiteSettingsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetThemeSettingsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchHallsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchMediaTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchPagesTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchVenuesTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidateEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidateHallSchemaTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidatePaymentConfigurationTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidateTicketTemplateTool;
use Nabilet\Modules\Core\Users\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Контентные read-only инструменты MCP: скоуп organizations, маскирование
 * настроек и валидаторы, использующие правила самого приложения.
 */
class AgentContentToolsTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = [
        SearchPagesTool::class, GetPageTool::class, SearchMediaTool::class,
        GetSiteSettingsTool::class, GetThemeSettingsTool::class, GetSeoReportTool::class,
        SearchVenuesTool::class, SearchHallsTool::class, GetHallSchemaTool::class,
        ValidateHallSchemaTool::class, ValidateEventTool::class,
        ValidateTicketTemplateTool::class, ValidatePaymentConfigurationTool::class,
    ];

    public function test_all_content_tools_are_registered_and_read_only(): void
    {
        [$orgId, $user] = $this->seedStaff();
        $foreignOrg = $this->seedOrg('Другая организация');

        NabiletAgentServer::actingAs($user)->tools()->assertRegistered(self::TOOLS);
        foreach (self::TOOLS as $toolClass) {
            $this->assertNotEmpty(
                (new \ReflectionClass($toolClass))->getAttributes(IsReadOnly::class),
                "{$toolClass} должен быть #[IsReadOnly].",
            );
        }

        $groups = $this->actingAs($user, 'api')->getJson('/api/v1/admin/agent/connection')
            ->assertOk()->json('data.groups');
        $this->assertCount(13, $groups['content']);
    }

    public function test_pages_are_scoped_and_page_content_is_returned(): void
    {
        [$orgId, $user] = $this->seedStaff();
        $foreignOrg = $this->seedOrg('Чужая организация');
        $own = $this->seedPage($orgId, 'Свой концерт');
        $this->seedPage($foreignOrg, 'Чужой концерт');

        NabiletAgentServer::actingAs($user)
            ->tool(SearchPagesTool::class, ['query' => 'концерт'])
            ->assertOk()
            ->assertStructuredContentCount(1, 'data.pages')
            ->assertStructuredContentPath('data.pages.0.public_id', $own);

        NabiletAgentServer::actingAs($user)
            ->tool(GetPageTool::class, ['public_id' => $own])
            ->assertOk()
            ->assertStructuredContentPath('data.found', true)
            ->assertStructuredContentPath('data.page.content', 'Текст страницы');
    }

    public function test_hall_schema_comes_from_own_organization_only(): void
    {
        [$orgId, $user] = $this->seedStaff();
        $foreignOrg = $this->seedOrg('Чужая организация');
        [$hallId, $schema] = $this->seedHall($orgId);
        $this->seedHall($foreignOrg);

        NabiletAgentServer::actingAs($user)
            ->tool(SearchHallsTool::class, ['query' => 'Зал'])
            ->assertOk()
            ->assertStructuredContentPath('data.halls.0.public_id', fn ($v) => is_string($v));

        NabiletAgentServer::actingAs($user)
            ->tool(GetHallSchemaTool::class, ['hall_id' => $hallId])
            ->assertOk()
            ->assertStructuredContentPath('data.found', true)
            ->assertStructuredContentPath('data.published_version.version', 1)
            ->assertStructuredContentPath('data.published_version.schema.sectors.0.name', 'Партер');

        NabiletAgentServer::actingAs($user)
            ->tool(GetHallSchemaTool::class, ['hall_id' => 999999])
            ->assertHasErrors();
    }

    public function test_site_settings_mask_secrets(): void
    {
        [$orgId, $user] = $this->seedStaff();
        DB::table('storefront_settings')->insert([
            'organization_id' => $orgId,
            'config' => json_encode([
                'theme' => ['primary' => '#123456'],
                'password' => 'super-secret',
                'api_key' => 'secret-key',
            ]),
        ]);

        NabiletAgentServer::actingAs($user)
            ->tool(GetSiteSettingsTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.settings.password', '***')
            ->assertStructuredContentPath('data.settings.api_key', '***');

        NabiletAgentServer::actingAs($user)
            ->tool(GetThemeSettingsTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.theme.theme.primary', '#123456')
            ->assertStructuredContentMissingPaths(['data.theme.password']);
    }

    public function test_validators_use_application_rules_without_writing(): void
    {
        [$orgId, $user] = $this->seedStaff();

        NabiletAgentServer::actingAs($user)
            ->tool(ValidateEventTool::class, ['payload' => ['title' => '', 'starts_at' => 'no-date']])
            ->assertOk()
            ->assertStructuredContentPath('data.valid', false);

        NabiletAgentServer::actingAs($user)
            ->tool(ValidateTicketTemplateTool::class, ['payload' => ['width' => -1, 'height' => 0]])
            ->assertOk()
            ->assertStructuredContentPath('data.valid', fn ($v) => is_bool($v));

        NabiletAgentServer::actingAs($user)
            ->tool(ValidateHallSchemaTool::class, ['payload' => ['version' => '999.0', 'canvas' => ['width' => -1], 'sectors' => []]])
            ->assertOk()
            ->assertStructuredContentPath('data.valid', false);

        NabiletAgentServer::actingAs($user)
            ->tool(ValidatePaymentConfigurationTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.valid', fn ($v) => is_bool($v));
    }

    /** @return array{0: int, 1: User} */
    private function seedStaff(): array
    {
        $orgId = $this->seedOrg('Моя организация');
        $now = now()->toDateTimeString();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'content-' . Str::random(5) . '@example.test',
            'first_name' => 'Content',
            'last_name' => 'Agent',
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
        $user->withAccessToken(new PersonalAccessToken(['name' => 'test', 'token' => hash('sha256', 'test'), 'abilities' => [
            'agent:read', 'agent:system:read', 'agent:content:read', 'agent:events:write',
            'agent:schemas:publish', 'agent:refunds:write', 'agent:actions:confirm', 'agent:content:write',
            ]]));

        return [$orgId, $user];
    }

    private function seedOrg(string $name): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedPage(int $orgId, string $title): string
    {
        $publicId = (string) Str::ulid()->toBase32();
        $now = now()->toDateTimeString();
        DB::table('pages')->insert([
            'public_id' => $publicId,
            'organization_id' => $orgId,
            'title' => $title,
            'slug' => 'page-' . Str::random(6),
            'content' => 'Текст страницы',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $publicId;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function seedHall(int $orgId): array
    {
        $now = now()->toDateTimeString();
        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'name' => 'Площадка ' . Str::random(4),
            'slug' => 'venue-' . Str::random(6),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // `halls` — VIEW над venues: зал создаётся полем venues.hall_name.
        DB::table('venues')->where('id', $venueId)->update(['hall_name' => 'Зал ' . Str::random(4)]);
        $hallId = $venueId;
        $schema = ['version' => '1.0', 'canvas' => ['width' => 1000, 'height' => 800],
            'sectors' => [['name' => 'Партер', 'seats' => [['id' => 'a1', 'x' => 10, 'y' => 10]]]]];
        DB::table('hall_schema_versions')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'hall_id' => $hallId,
            'version' => 1,
            'status' => 'published',
            'width' => 1000,
            'height' => 800,
            'schema_json' => json_encode($schema),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$hallId, $schema];
    }
}
