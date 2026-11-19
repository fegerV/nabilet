<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Support\AgentCapabilities;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Двухфазные write-действия агента: подготовил → человек одобрил → исполнил.
 *
 * Ключевые инварианты:
 *  - подготовка НЕ меняет бизнес-данные (только agent_actions);
 *  - без одобрения исполнение невозможно;
 *  - подтверждение привязано к организации одобряющего;
 *  - повторное исполнение возвращает сохранённый результат;
 *  - токен без write-способности не может подготовить действие.
 */
class AgentWriteFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_approve_execute_is_idempotent(): void
    {
        [$orgId, $user] = $this->seedStaffWithOrg();
        [$venueId, $schemaVersionId] = $this->seedVenueWithSchema($orgId);
        $token = $user->createToken('agent', [
            AgentCapabilities::READ,
            AgentCapabilities::EVENTS_WRITE,
            AgentCapabilities::ACTIONS_CONFIRM,
        ])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'];

        // 1. Подготовка — черновик ещё НЕ создан.
        $prep = $this->mcp('tools/call', [
            'name' => 'create_event',
            'arguments' => [
                'title' => 'Концерт ИИ',
                'venue_id' => $venueId,
                'schema_version_id' => $schemaVersionId,
                'starts_at' => now()->addWeek()->toDateTimeString(),
            ],
        ], $headers);
        $prep->assertOk()->assertJsonPath('result.isError', false);
        $publicId = $prep->json('result.structuredContent.action_public_id');
        $this->assertNotEmpty($publicId);
        $this->assertSame(0, DB::table('events')->count(), 'подготовка не создаёт мероприятие');
        $this->assertSame(AgentAction::STATUS_PENDING, DB::table('agent_actions')->where('public_id', $publicId)->value('status'));

        // 2. Исполнение без одобрения — отказ.
        $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers)->assertOk()->assertJsonPath('result.isError', true);

        // 3. Человек одобряет (админский API, сессия того же пользователя).
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/admin/agent/actions/'.DB::table('agent_actions')->where('public_id', $publicId)->value('id').'/approve', [])
            ->assertOk();

        // 4. Исполнение — ровно одно событие.
        $exec = $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers);
        $exec->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame('draft', DB::table('events')->value('status'), 'агент не публикует в обход политики');

        // 5. Повтор — тот же результат, второго события нет.
        $again = $this->mcp('tools/call', [
            'name' => 'confirm_agent_action',
            'arguments' => ['action_public_id' => $publicId],
        ], $headers);
        $again->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame(
            $exec->json('result.structuredContent.event_public_id'),
            $again->json('result.structuredContent.event_public_id'),
        );
    }

    public function test_token_without_write_capability_cannot_prepare(): void
    {
        [, $user] = $this->seedStaffWithOrg();
        $token = $user->createToken('agent', [AgentCapabilities::READ])->plainTextToken;

        $this->mcp('tools/call', [
            'name' => 'create_event',
            'arguments' => ['title' => 'X', 'venue_id' => 1, 'starts_at' => now()->toDateTimeString()],
        ], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $this->assertSame(0, DB::table('agent_actions')->count());
    }

    /** @return array{0: int, 1: User} */
    private function seedStaffWithOrg(): array
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
            'email' => 'write-'.Str::random(6).'@example.test',
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

        return [$orgId, User::query()->findOrFail($userId)];
    }

    /** @return array{0: int, 1: int} [venueId, published schema versionId] */
    private function seedVenueWithSchema(int $orgId): array
    {
        $now = now()->toDateTimeString();
        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'name' => 'Зал',
            'slug' => 'venue-'.Str::random(6),
            'status' => 'active',
            'hall_name' => 'Основной зал',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $schemaId = (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $orgId,
            'hall_id' => $venueId,
            'venue_id' => $venueId,
            'version' => 1,
            'status' => 'published',
            'schema_json' => json_encode(['version' => 1, 'canvas' => ['width' => 900, 'height' => 520], 'sectors' => []]),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$venueId, $schemaId];
    }

    private function mcp(string $method, array $params, array $headers): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => random_int(100, 999),
            'method' => $method,
            'params' => $params,
        ], $headers);
    }
}
