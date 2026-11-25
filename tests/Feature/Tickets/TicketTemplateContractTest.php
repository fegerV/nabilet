<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Tickets\Models\TicketTemplate;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Nabilet\Modules\Tickets\Services\TicketPdfRenderer;
use Tests\TestCase;

/**
 * Единый контракт шаблона билета (аудит 2026-11-26).
 *
 * Проверяем не только «endpoint ответил 200», а и Content-Type,
 * геометрию в HTML и отсутствие сырых плейсхолдеров у покупателя.
 */
final class TicketTemplateContractTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizationId = $this->seedOrganization();
        $this->actingAsAdmin($this->organizationId);
    }

    public function test_canvas_inside_template_json_is_the_only_size_source(): void
    {
        $response = $this->postJson('/api/v1/ticket-templates', [
            'name' => 'Горизонтальный билет',
            'width' => 400,
            'height' => 600,
            'template_json' => [
                'canvas' => ['width' => 800, 'height' => 400, 'unit' => 'px'],
                'elements' => [$this->textElement('{{event.name}}')],
            ],
        ])->assertCreated();

        $id = (int) $response->json('data.id');
        $template = TicketTemplate::query()->findOrFail($id);

        $this->assertSame(800, (int) $template->width);
        $this->assertSame(400, (int) $template->height);
        $this->assertSame(800, (int) $template->template_json['canvas']['width']);
    }

    public function test_strict_validation_rejects_unknown_types_and_out_of_range_rotation(): void
    {
        $this->postJson('/api/v1/ticket-templates', [
            'name' => 'Плохой макет',
            'width' => 400,
            'height' => 600,
            'template_json' => [
                'elements' => [
                    ['id' => 'a', 'type' => 'triangle', 'x' => 0, 'y' => 0, 'rotation' => 1_000_000],
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_TEMPLATE_INVALID')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['elements.0.type']]]]);
    }

    public function test_pdf_html_renders_real_barcode_rotation_opacity_and_circle_radius(): void
    {
        $config = [
            'canvas' => ['width' => 400, 'height' => 600, 'unit' => 'px'],
            'elements' => [
                [
                    'id' => 'text-1', 'type' => 'text', 'x' => 10, 'y' => 20,
                    'width' => 300, 'height' => 40, 'rotation' => 15, 'opacity' => 0.5,
                    'content' => '{{event.name}} {{event.unknown}}',
                ],
                [
                    'id' => 'circle-1', 'type' => 'circle', 'x' => 40, 'y' => 100,
                    'r' => 50, 'width' => 1, 'height' => 1,
                ],
                [
                    'id' => 'barcode-1', 'type' => 'barcode', 'x' => 20, 'y' => 300,
                    'width' => 250, 'height' => 60, 'data' => '{{ticket.number}}',
                ],
                [
                    'id' => 'qr-1', 'type' => 'qr', 'x' => 20, 'y' => 400, 'size' => 140,
                ],
            ],
        ];

        $data = app(TicketGeneratorService::class)->previewData();
        $html = app(TicketPdfRenderer::class)->renderHtmlFromConfig($config, $data);

        $this->assertStringContainsString('rotate(15.000000deg)', $html);
        $this->assertStringContainsString('opacity:0.500000', $html);
        $this->assertStringContainsString('width:100.000000px;height:100.000000px', $html, 'круг обязан использовать r, а не width/height');
        $this->assertStringContainsString('data:image/svg+xml;base64', $html, 'штрихкод должен быть настоящим SVG, а не заглушкой');
        $this->assertStringContainsString('data:image/png;base64', $html, 'QR строится локально');
        $this->assertStringNotContainsString('{{', $html, 'неизвестные переменные не уходят покупателю');
        $this->assertStringContainsString('Пример мероприятия', $html);
    }

    public function test_preview_endpoint_uses_the_same_renderer_and_marks_the_sample(): void
    {
        $response = $this->postJson('/api/v1/ticket-templates', [
            'name' => 'Шаблон для предпросмотра',
            'width' => 400,
            'height' => 600,
            'template_json' => ['elements' => [$this->textElement('{{ticket.price}}')]],
        ])->assertCreated();
        $id = (int) $response->json('data.id');

        $this->getJson("/api/v1/ticket-templates/{$id}/preview?format=html")
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=utf-8')
            ->assertSee('ОБРАЗЕЦ')
            ->assertSee('3 500 ₽', false)
            ->assertDontSee('{{');
    }

    public function test_update_requires_a_fresh_base_updated_at(): void
    {
        $id = $this->createTemplate();
        $template = TicketTemplate::query()->findOrFail($id);

        $this->putJson("/api/v1/ticket-templates/{$id}", [
            'name' => 'Новое имя',
            'template_json' => ['elements' => [$this->textElement('x')]],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['base_updated_at']]]]);

        $this->putJson("/api/v1/ticket-templates/{$id}", [
            'base_updated_at' => '2000-01-01T00:00:00+00:00',
            'name' => 'Новое имя',
        ])->assertStatus(409)->assertJsonPath('error.code', 'TICKET_TEMPLATE_CONFLICT');

        $this->assertSame('Исходный макет', $template->fresh()->name);
    }

    public function test_disabled_assigned_template_is_not_used_for_tickets(): void
    {
        $templateId = $this->createTemplate();
        DB::table('ticket_templates')->where('id', $templateId)->update(['active' => false]);

        // Резолвер больше не отдаёт отключённый макет, даже если он назначен.
        $resolver = $this->app->make(\Nabilet\Modules\Tickets\Services\TicketTemplateResolver::class);
        $method = new \ReflectionMethod($resolver, 'assignedTemplate');
        $eventId = $this->createEventWithTemplate($templateId);

        $this->assertNull($method->invoke($resolver, $eventId, $this->organizationId));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function textElement(string $content): array
    {
        return [
            'id' => 'text-1', 'type' => 'text', 'x' => 10, 'y' => 20,
            'width' => 300, 'height' => 40, 'content' => $content,
        ];
    }

    private function createEventWithTemplate(int $templateId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'title' => 'Событие с отключённым макетом',
            'slug' => 'disabled-' . Str::lower(Str::random(8)),
            'status' => 'published',
            'ticket_template_id' => $templateId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createTemplate(): int
    {
        $response = $this->postJson('/api/v1/ticket-templates', [
            'name' => 'Исходный макет',
            'width' => 400,
            'height' => 600,
            'template_json' => ['elements' => [$this->textElement('{{event.name}}')]],
        ])->assertCreated();

        return (int) $response->json('data.id');
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Билетная организация',
            'slug' => 'ticket-' . Str::random(8),
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
            'status' => 'active', 'locale' => 'ru', 'timezone' => 'UTC',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        DB::table('user_roles')->insert([
            'user_id' => $userId, 'organization_id' => $organizationId,
            'role_id' => $roleId, 'created_at' => $now,
        ]);
        DB::table('user_organization')->insert([
            'user_id' => $userId, 'organization_id' => $organizationId,
            'role_id' => $roleId, 'created_at' => $now,
        ]);
        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }
}
