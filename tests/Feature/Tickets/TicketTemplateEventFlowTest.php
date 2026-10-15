<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Tickets\Models\TicketTemplate;
use Tests\TestCase;

/**
 * Свой макет для мероприятия.
 *
 * Контракт, который здесь зафиксирован:
 *
 * 1. Новое мероприятие без выбранного макета получает макет по умолчанию
 *    (`EventController::store` → `TicketTemplateService::ensureDefaultTemplate()`),
 *    а не NULL. Если макетов у организации нет вообще — создаётся «Стандартный».
 * 2. Явный выбор администратора не перетирается.
 * 3. `POST /ticket-templates/{id}/duplicate` копирует макет целиком
 *    (типографика и фон — это `template_json`) и по `event_id` сразу
 *    назначает копию мероприятию. Чужой макет — 404, чужое мероприятие — 422.
 * 4. Список макетов показывает, чьи билеты каждый оформляет (`events`),
 *    чтобы `/admin/ticket-templates` отвечал на вопрос «где созданные билеты».
 */
class TicketTemplateEventFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_event_without_a_template_assigns_the_default(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $eventId = $this->createEvent($orgId, ['slug' => 'bez-maketa-' . Str::random(6)]);

        $templateId = DB::table('events')->where('id', $eventId)->value('ticket_template_id');

        $this->assertNotNull($templateId, 'мероприятие должно получить макет по умолчанию, а не NULL');

        $template = TicketTemplate::query()->findOrFail((int) $templateId);

        $this->assertSame('Стандартный', $template->name);
        $this->assertTrue((bool) $template->active);
        $this->assertSame($orgId, (int) $template->organization_id);
        $this->assertNotEmpty($template->template_json['elements'] ?? []);
    }

    public function test_creating_an_event_reuses_the_first_active_template(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $firstId = $this->seedTemplate($orgId, 'Первый макет');
        $this->seedTemplate($orgId, 'Второй макет');

        $eventId = $this->createEvent($orgId, ['slug' => 'povtor-' . Str::random(6)]);

        $this->assertSame(
            $firstId,
            (int) DB::table('events')->where('id', $eventId)->value('ticket_template_id'),
            'назначается первый активный макет организации'
        );
        $this->assertSame(2, TicketTemplate::query()->where('organization_id', $orgId)->count());
    }

    public function test_an_explicit_template_choice_is_kept(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $this->seedTemplate($orgId, 'Макет по умолчанию');
        $chosenId = $this->seedTemplate($orgId, 'Выбранный макет');

        $eventId = $this->createEvent($orgId, [
            'slug' => 's-vyborom-' . Str::random(6),
            'ticket_template_id' => $chosenId,
        ]);

        $this->assertSame(
            $chosenId,
            (int) DB::table('events')->where('id', $eventId)->value('ticket_template_id')
        );
    }

    public function test_duplicate_copies_the_layout_and_assigns_the_event(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $sourceId = $this->seedTemplate($orgId, 'Исходник', [
            'backgroundColor' => '#123456',
            'elements' => [['type' => 'text', 'content' => 'Привет', 'fontSize' => 42]],
        ]);
        $eventId = $this->createEvent($orgId, ['slug' => 'dlya-dublya-' . Str::random(6)]);

        $response = $this->postJson("/api/v1/ticket-templates/{$sourceId}/duplicate", [
            'name' => 'Концерт — билет',
            'event_id' => $eventId,
        ]);

        $response->assertStatus(201);

        $copyId = (int) $response->json('data.id');
        $this->assertNotSame($sourceId, $copyId);

        $copy = TicketTemplate::query()->findOrFail($copyId);

        $this->assertSame('Концерт — билет', $copy->name);
        $this->assertSame('#123456', $copy->template_json['backgroundColor']);
        $this->assertSame(
            [['type' => 'text', 'content' => 'Привет', 'fontSize' => 42]],
            $copy->template_json['elements'],
            'типографика и фон наследуются целиком'
        );

        $this->assertSame(
            $copyId,
            (int) DB::table('events')->where('id', $eventId)->value('ticket_template_id'),
            'копия сразу назначена мероприятию'
        );
    }

    public function test_duplicate_rejects_an_event_from_another_organization(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $sourceId = $this->seedTemplate($orgId, 'Исходник');

        $foreignOrgId = $this->seedOrganization('Чужой организатор');
        $foreignEventId = (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $foreignOrgId,
            'title' => 'Чужое событие',
            'slug' => 'chuzhoe-' . Str::random(6),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/ticket-templates/{$sourceId}/duplicate", [
            'event_id' => $foreignEventId,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_duplicate_of_a_foreign_template_is_not_found(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $foreignOrgId = $this->seedOrganization('Чужой организатор');
        $foreignTemplateId = $this->seedTemplate($foreignOrgId, 'Чужой макет');

        $this->postJson("/api/v1/ticket-templates/{$foreignTemplateId}/duplicate", [])
            ->assertStatus(404);
    }

    public function test_the_template_list_shows_which_events_use_each_template(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $templateId = $this->seedTemplate($orgId, 'Макет с привязкой');
        $eventId = $this->createEvent($orgId, [
            'slug' => 's-privyazkoy-' . Str::random(6),
            'ticket_template_id' => $templateId,
        ]);

        $response = $this->getJson('/api/v1/ticket-templates');

        $response->assertOk();

        $rows = $response->json('data');
        $this->assertIsArray($rows);

        $row = collect($rows)->firstWhere('id', $templateId);
        $this->assertNotNull($row, 'макет должен быть в списке');

        $this->assertSame(
            [['id' => $eventId, 'title' => 'Концерт «Секрет»']],
            array_values($row['events'] ?? []),
            'список показывает, чьи билеты оформляет макет'
        );
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    private function seedOrganization(string $name = 'Сургут-Концерт'): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name . ' ' . Str::random(4),
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function actingAsAdmin(int $organizationId): User
    {
        $now = now();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }

    private function seedTemplate(int $organizationId, string $name, ?array $templateJson = null): int
    {
        return (int) TicketTemplate::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'format' => 'mobile',
            'width' => 600,
            'height' => 400,
            'template_json' => $templateJson ?? ['elements' => []],
            'active' => true,
        ])->id;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createEvent(int $organizationId, array $extra = []): int
    {
        $response = $this->postJson('/api/v1/events', array_merge([
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-' . Str::random(8),
        ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }
}
