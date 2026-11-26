<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketPdfRenderer;
use Tests\TestCase;

/**
 * Неизменяемый снимок шаблона на момент выпуска билета (аудит 2026-11-26).
 *
 * Фиксируется ОФОРМЛЕНИЕ; динамические данные (дата/площадка/цена) продолжают
 * читаться из текущего сеанса/заказа, а подписанный QR не меняется.
 */
final class TicketTemplateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;
    private int $eventId;
    private int $sessionId;
    private int $inventoryId;
    private int $orderItemId;
    private int $oldTemplateId;
    private int $newTemplateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationId = $this->seedOrganization();
        $this->eventId = $this->seedEvent();
        $venueId = $this->seedVenue();
        $schemaId = $this->seedSchema($venueId);
        $this->sessionId = $this->seedSession($venueId, $schemaId);
        $seatId = $this->seedSeat($schemaId);
        $this->inventoryId = $this->seedInventoryItem($seatId);
        $orderId = $this->seedOrder();
        $this->orderItemId = $this->seedOrderItem($orderId, $this->inventoryId);

        $this->oldTemplateId = $this->seedTemplate('СТАРЫЙ_ДИЗАЙН');
        $this->newTemplateId = $this->seedTemplate('НОВЫЙ_ДИЗАЙН');
        DB::table('events')->where('id', $this->eventId)->update(['ticket_template_id' => $this->newTemplateId]);
    }

    public function test_snapshot_keeps_the_design_that_was_sold(): void
    {
        $this->assertNotSame($this->oldTemplateId, $this->newTemplateId);
        $this->attachTicket('{{event.name}} СТАРЫЙ_ДИЗАЙН');

        $html = app(TicketPdfRenderer::class)->renderHtml($this->ticket());

        $this->assertStringContainsString('СТАРЫЙ_ДИЗАЙН', $html);
        $this->assertStringNotContainsString('НОВЫЙ_ДИЗАЙН', $html);
    }

    public function test_snapshot_embeds_image_bytes_and_survives_media_deletion(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('templates/poster.png', 'PNG-BYTES');
        $mediaId = 'poster-asset-' . Str::random(6);
        $now = now()->toDateTimeString();
        $assetId = (int) DB::table('media_assets')->insertGetId([
            'public_id' => $mediaId,
            'organization_id' => $this->organizationId,
            'disk' => 'public',
            'path' => 'templates/poster.png',
            'filename' => 'poster.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $config = \Nabilet\Modules\Tickets\Domain\TicketTemplateConfig::normalize([
            'elements' => [[
                'id' => 'poster', 'type' => 'image', 'x' => 10, 'y' => 10,
                'width' => 100, 'height' => 100, 'media_id' => $mediaId,
            ]],
        ]);
        DB::table('ticket_templates')->where('id', $this->newTemplateId)->update([
            'template_json' => json_encode($config),
        ]);

        $found = app(OrganizationContext::class)->withoutScope(
            fn () => \Nabilet\Modules\Media\Models\MediaAsset::query()->where('public_id', $mediaId)->first()
        );
        $this->assertNotNull($found, 'Медиаресурс должен быть доступен выпуску билета');
        $this->assertTrue(Storage::disk('public')->exists('templates/poster.png'));
        $orderId = $this->seedOrder();
        $this->seedOrderItem($orderId, $this->inventoryId);
        $order = app(OrganizationContext::class)->withoutScope(
            fn () => \Nabilet\Modules\Orders\Models\Order::query()->findOrFail($orderId)
        );
        $issued = app(\Nabilet\Modules\Tickets\Services\TicketService::class)->issueTicketsForOrder($order);
        $this->assertNotEmpty($issued);

        $snapshot = DB::table('order_items')
            ->where('order_id', $orderId)->whereNotNull('template_snapshot_json')
            ->value('template_snapshot_json');
        $this->assertNotNull($snapshot);
        $decodedSnapshot = json_decode((string) $snapshot, true);
        $this->assertStringStartsWith(
            'data:image/png;base64,',
            (string) ($decodedSnapshot['elements'][0]['src'] ?? ''),
        );

        // Удаляем исходный ресурс; билет обязан сохранить встроенные байты.
        DB::table('media_assets')->where('id', $assetId)->delete();
        Storage::disk('public')->delete('templates/poster.png');
        $ticket = app(OrganizationContext::class)->withoutScope(
            fn (): Ticket => Ticket::query()->findOrFail($issued[0]->id)
        );
        $html = app(TicketPdfRenderer::class)->renderHtml($ticket);
        $this->assertStringContainsString('data:image/png;base64', $html);
    }

    public function test_legacy_ticket_without_snapshot_uses_the_live_template(): void
    {
        $this->attachTicket(null);

        $html = app(TicketPdfRenderer::class)->renderHtml($this->ticket());

        $this->assertStringContainsString('НОВЫЙ_ДИЗАЙН', $html);
        $this->assertStringNotContainsString('СТАРЫЙ_ДИЗАЙН', $html);
    }

    public function test_dynamic_event_data_still_updates_under_a_frozen_design(): void
    {
        $this->attachTicket('{{event.date}} СТАРЫЙ_ДИЗАЙН');
        $before = app(TicketPdfRenderer::class)->renderHtml($this->ticket());
        $this->assertStringContainsString('20.11.2026', $before);

        DB::table('sessions')->where('id', $this->sessionId)->update(['starts_at' => '2026-12-25 19:00:00']);
        $after = app(TicketPdfRenderer::class)->renderHtml($this->ticket());

        $this->assertStringContainsString('25.12.2026', $after);
        $this->assertStringContainsString('СТАРЫЙ_ДИЗАЙН', $after, 'оформление не должно меняться вместе с датой');
    }

    public function test_issuance_freezes_the_design_on_the_order_item(): void
    {
        // Отдельный заказ: восстановление/idempotency не должно выпускать
        // повторно билеты уже выпущенного заказа из setUp().
        $orderId = $this->seedOrder();
        $newItemId = $this->seedOrderItem($orderId, $this->inventoryId);

        $order = app(OrganizationContext::class)->withoutScope(
            fn () => \Nabilet\Modules\Orders\Models\Order::query()->findOrFail($orderId)
        );
        $issued = app(\Nabilet\Modules\Tickets\Services\TicketService::class)
            ->issueTicketsForOrder($order);

        $this->assertNotEmpty($issued);
        $snapshot = DB::table('order_items')->where('id', $newItemId)->value('template_snapshot_json');
        $this->assertNotNull($snapshot, 'выпущенный билет обязан нести снимок оформления');
        $decoded = json_decode((string) $snapshot, true);
        $this->assertSame('1.0', $decoded['version'] ?? null);
        $this->assertSame('НОВЫЙ_ДИЗАЙН', (string) ($decoded['elements'][0]['content'] ?? ''));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ticket(): Ticket
    {
        return app(OrganizationContext::class)->withoutScope(
            fn (): Ticket => Ticket::query()->findOrFail($this->orderItemId)
        );
    }

    private function attachTicket(?string $snapshotContent): void
    {
        $snapshot = null;
        if ($snapshotContent !== null) {
            $snapshot = \Nabilet\Modules\Tickets\Domain\TicketTemplateConfig::normalize([
                'elements' => [[
                    'id' => 'copy', 'type' => 'text', 'x' => 10, 'y' => 20,
                    'width' => 360, 'height' => 40, 'content' => $snapshotContent,
                ]],
            ]);
        }

        DB::table('order_items')->where('id', $this->orderItemId)->update([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'ticket_number' => 'TCK-SNAPSHOT-001',
            'ticket_index' => 1,
            'event_id' => $this->eventId,
            'session_id' => $this->sessionId,
            'seat_id' => (int) DB::table('inventory_items')->where('id', $this->inventoryId)->value('seat_id'),
            'holder_name' => 'Покупатель',
            'ticket_status' => 'issued',
            'qr_version' => 1,
            'qr_token_hash' => hash('sha256', 'test-token'),
            'qr_payload' => 'NB1.test-public-id.token.signature',
            'template_snapshot_json' => $snapshot === null ? null : json_encode($snapshot),
            'issued_at' => now()->toDateTimeString(),
        ]);
    }

    private function seedTemplate(string $content): int
    {
        $snapshot = \Nabilet\Modules\Tickets\Domain\TicketTemplateConfig::normalize([
            'elements' => [[
                'id' => 'copy', 'type' => 'text', 'x' => 10, 'y' => 20,
                'width' => 360, 'height' => 40, 'content' => $content,
            ]],
        ]);
        $now = now()->toDateTimeString();

        return (int) DB::table('ticket_templates')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'name' => 'Шаблон ' . $content,
            'format' => 'mobile',
            'width' => 400,
            'height' => 600,
            'template_json' => json_encode($snapshot),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Снимок билета',
            'slug' => 'snapshot-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedEvent(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'title' => 'Концерт со снимком',
            'slug' => 'snapshot-event-' . Str::random(6),
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVenue(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'name' => 'Зал для снимка',
            'slug' => 'snapshot-venue-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedSchema(int $venueId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('hall_schema_versions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'hall_id' => $venueId,
            'venue_id' => $venueId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'schema_json' => json_encode(['sectors' => []]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedSession(int $venueId, int $schemaId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'event_id' => $this->eventId,
            'venue_id' => $venueId,
            'hall_id' => $venueId,
            'schema_version_id' => $schemaId,
            'starts_at' => '2026-11-20 19:00:00',
            'timezone' => 'Europe/Moscow',
            'status' => 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedSeat(int $schemaId): int
    {
        $now = now()->toDateTimeString();
        $sectorId = (int) DB::table('sectors')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'schema_version_id' => $schemaId,
            'name' => 'Партер',
            'code' => 'Parter',
            'type' => 'seated',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $rowId = (int) DB::table('hall_rows')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'sector_id' => $sectorId,
            'number' => '1',
            'name' => 'Партер',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('seats')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'row_id' => $rowId,
            'number' => '12',
            'label' => 'Место 12',
            'type' => 'standard',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedInventoryItem(int $seatId): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('inventory_items')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'session_id' => $this->sessionId,
            'type' => 'seat',
            'seat_id' => $seatId,
            'price_amount' => 350000,
            'capacity' => 1,
            'available_quantity' => 0,
            'status' => 'sold',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrder(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'order_number' => 'NB-20261126-' . Str::upper(Str::random(8)),
            'organization_id' => $this->organizationId,
            'event_id' => $this->eventId,
            'session_id' => $this->sessionId,
            'subtotal_amount' => 350000,
            'total_amount' => 350000,
            'currency' => 'RUB',
            'status' => 'paid',
            'payment_status' => 'succeeded',
            'customer_email' => 'buyer@example.test',
            'customer_name' => 'Покупатель',
            'created_at' => $now,
            'paid_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOrderItem(int $orderId, int $inventoryId): int
    {
        return (int) DB::table('order_items')->insertGetId([
            'organization_id' => $this->organizationId,
            'order_id' => $orderId,
            'inventory_item_id' => $inventoryId,
            'quantity' => 1,
            'unit_price' => 350000,
            'total_amount' => 350000,
            'event_title_snapshot' => 'Концерт со снимком',
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}
