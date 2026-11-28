<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Modules\Agent\Mcp\NabiletAgentServer;
use Nabilet\Modules\Agent\Mcp\Tools\GetApiCapabilitiesTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetApplicationVersionTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetBackupStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetCronStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetDatabaseStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetFailedJobsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetInstallationReportTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetIntegrationStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetModuleStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetQueueStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetRecentErrorsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetRouteStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSystemRequirementsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSystemStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetUpdateStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\RunReadonlySelfcheckTool;
use Nabilet\Modules\Core\Users\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Диагностические инструменты MCP: состав, аннотации и обезличенность.
 *
 * Все инструменты этой группы обязаны быть read-only — клиент вызывает их без
 * подтверждения человека, поэтому любая запись или утечка секрета здесь
 * недопустима. Тест проверяет и объявление, и фактический ответ.
 */
class AgentDiagnosticsToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string> */
    private const DIAGNOSTICS = [
        GetApplicationVersionTool::class,
        GetSystemStatusTool::class,
        GetSystemRequirementsTool::class,
        GetCronStatusTool::class,
        GetQueueStatusTool::class,
        GetRecentErrorsTool::class,
        GetFailedJobsTool::class,
        GetDatabaseStatusTool::class,
        GetModuleStatusTool::class,
        GetIntegrationStatusTool::class,
        GetBackupStatusTool::class,
        GetUpdateStatusTool::class,
        GetApiCapabilitiesTool::class,
        GetInstallationReportTool::class,
        RunReadonlySelfcheckTool::class,
        GetRouteStatusTool::class,
    ];

    public function test_all_diagnostic_tools_are_registered_and_annotated_read_only(): void
    {
        $user = $this->seedStaff();

        NabiletAgentServer::actingAs($user)->tools()->assertRegistered(self::DIAGNOSTICS);

        foreach (self::DIAGNOSTICS as $toolClass) {
            $attributes = (new \ReflectionClass($toolClass))->getAttributes(IsReadOnly::class);
            $this->assertNotEmpty($attributes, "{$toolClass} должен быть #[IsReadOnly].");
        }
    }

    public function test_version_and_requirements_report_no_secrets(): void
    {
        $user = $this->seedStaff();

        NabiletAgentServer::actingAs($user)
            ->tool(GetApplicationVersionTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.version', (string) config('nabilet.version'))
            ->assertStructuredContentMissingPaths([
                'data.env',
                'data.app_key',
            ]);

        NabiletAgentServer::actingAs($user)
            ->tool(GetSystemRequirementsTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.php.minimum', '8.3.0')
            ->assertStructuredContentMissingPaths([
                'data.env',
                'data.db_password',
            ]);
    }

    public function test_system_status_and_selfcheck_are_read_only(): void
    {
        $user = $this->seedStaff();

        NabiletAgentServer::actingAs($user)
            ->tool(GetSystemStatusTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.status', fn (string $status) => in_array($status, ['ok', 'degraded', 'down'], true));

        NabiletAgentServer::actingAs($user)
            ->tool(RunReadonlySelfcheckTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.ok', fn (bool $ok) => is_bool($ok));
    }

    public function test_queue_and_failed_jobs_never_expose_payloads(): void
    {
        $user = $this->seedStaff();

        // В проваленной задаче лежат секреты: агент не должен их увидеть.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['email' => 'secret@example.test', 'token' => 'secret-token-123']),
            'exception' => 'RuntimeException: secret-token-123',
            'failed_at' => now()->toDateTimeString(),
        ]);

        NabiletAgentServer::actingAs($user)
            ->tool(GetQueueStatusTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.pending_jobs', fn ($value) => is_int($value));

        NabiletAgentServer::actingAs($user)
            ->tool(GetFailedJobsTool::class, ['limit' => 50])
            ->assertOk()
            ->assertStructuredContentPath('data.entries.0.queue', 'default')
            ->assertStructuredContentMissingPaths([
                'data.entries.0.payload',
                'data.entries.0.exception',
            ]);
    }

    public function test_installation_report_is_anonymous(): void
    {
        $user = $this->seedStaff();

        NabiletAgentServer::actingAs($user)
            ->tool(GetInstallationReportTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.application.version', (string) config('nabilet.version'))
            ->assertStructuredContentMissingPaths([
                'data.integrations.payments.yookassa.secret_key',
                'data.integrations.smtp.password',
                'data.database.password',
            ]);

        NabiletAgentServer::actingAs($user)
            ->tool(GetApiCapabilitiesTool::class)
            ->assertOk()
            ->assertStructuredContentPath('data.transport', 'Streamable HTTP');
    }

    public function test_route_status_reports_critical_routes_without_calling_them(): void
    {
        $user = $this->seedStaff();

        NabiletAgentServer::actingAs($user)
            ->tool(GetRouteStatusTool::class)
            ->assertOk()
            ->assertStructuredContentCount(4, 'data.critical')
            ->assertStructuredContentPath('data.critical.0.present', true)
            ->assertStructuredContentPath('data.critical.1.present', true);
    }

    public function test_admin_connection_endpoint_lists_real_tools_and_checks_route(): void
    {
        $user = $this->seedStaff();

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/agent/connection')
            ->assertOk()
            ->assertJsonPath('data.checks.route_registered', true)
            ->assertJsonPath('data.checks.active_tokens', 0)
            ->assertJsonPath('data.transport', 'Streamable HTTP');

        $groups = $response->json('data.groups');
        $this->assertContains('sales_summary', array_column($groups['core'], 'name'));
        $this->assertCount(16, $groups['diagnostics']);
        $this->assertTrue($groups['diagnostics'][0]['read_only']);
    }

    public function test_connection_check_sees_an_active_agent_token(): void
    {
        $user = $this->seedStaff();
        $user->createToken('agent', ['agent:read']);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/agent/connection')
            ->assertOk()
            ->assertJsonPath('data.checks.active_tokens', 1)
            ->assertJsonPath('data.checks.ok', true);
    }

    private function seedStaff(): User
    {
        $now = now()->toDateTimeString();
        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'MCP Diagnostics Org',
            'slug' => 'org-mcp-' . Str::random(5),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'mcp-diag-' . Str::random(5) . '@example.test',
            'first_name' => 'Diagnostics',
            'last_name' => 'User',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $user->withAccessToken(new PersonalAccessToken(['name' => 'test', 'token' => hash('sha256', 'test'), 'abilities' => [
            'agent:read', 'agent:system:read', 'agent:content:read', 'agent:events:write',
            'agent:schemas:publish', 'agent:refunds:write', 'agent:actions:confirm', 'agent:content:write',
            ]]));

        return $user;
    }
}
