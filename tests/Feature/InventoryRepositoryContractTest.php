<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\CarbonImmutable;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Inventory\Repositories\InventoryItemRepository;
use Nabilet\Modules\Tickets\Repositories\TicketRepository;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketTemplate;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Tickets\Models\OfflineBundle;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use App\Modules\Notifications\Services\NewsletterService;
use App\Modules\Notifications\Mail\TicketPurchasedMail;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\OrderItem;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\Seat;
use Nabilet\Modules\Venues\Models\HallRow;
use Nabilet\Modules\Venues\Models\Venue;
use App\Models\User;
use Tests\TestCase;

final class InventoryRepositoryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_related_models_only_expose_columns_from_the_schema(): void
    {
        $models = [
            new TicketTemplate(),
            new CheckinDevice(),
            new OfflineBundle(),
        ];
        foreach ($models as $model) {
            $columns = Schema::getColumnListing($model->getTable());
            $this->assertSame([], array_values(array_diff($model->getFillable(), $columns)), $model->getTable() . ' fillable fields must exist in MySQL');
        }

        $this->assertSame([
            'public_id', 'organization_id', 'name', 'format', 'width', 'height', 'template_json', 'active',
        ], (new TicketTemplate())->getFillable());
        $this->assertSame([
            'public_id', 'organization_id', 'name', 'device_token_hash', 'platform', 'app_version', 'status', 'last_seen_at',
        ], (new CheckinDevice())->getFillable());
        $this->assertSame([
            'public_id', 'organization_id', 'checkin_device_id', 'event_id', 'session_id', 'bundle_hash',
            'schema_version', 'public_key_fingerprint', 'ticket_count', 'revoked_count', 'payload_json',
            'status', 'generated_at', 'downloaded_at', 'expires_at',
        ], (new OfflineBundle())->getFillable());

        $this->assertSame(
            (new TicketTemplate())->getFillable(),
            (new \App\Models\TicketTemplate())->getFillable()
        );
        $this->assertSame(
            (new CheckinDevice())->getFillable(),
            (new \App\Models\CheckinDevice())->getFillable()
        );
        $this->assertSame(
            (new OfflineBundle())->getFillable(),
            (new \App\Models\OfflineBundle())->getFillable()
        );
    }

    public function test_paginated_repository_methods_advertise_their_actual_return_type(): void
    {
        $expected = \Illuminate\Contracts\Pagination\LengthAwarePaginator::class;
        $this->assertSame($expected, (new \ReflectionMethod(InventoryItemRepository::class, 'findBySession'))->getReturnType()->getName());
        $this->assertSame($expected, (new \ReflectionMethod(InventoryItemRepository::class, 'findBySessionAndStatus'))->getReturnType()->getName());
        $this->assertSame($expected, (new \ReflectionMethod(TicketRepository::class, 'findBySession'))->getReturnType()->getName());
    }

    public function test_issued_ticket_data_and_email_use_the_signed_ticket_contract(): void
    {
        Mail::fake();

        $user = new User();
        $user->name = 'Иван Покупатель';
        $user->email = 'buyer@example.test';

        $order = new Order();
        $order->id = 42;
        $order->customer_email = 'buyer@example.test';
        $order->customer_name = 'Иван Покупатель';
        $order->currency = 'RUB';
        $order->setRelation('user', $user);

        $orderItem = new OrderItem();
        $orderItem->unit_price = 12500;

        $event = new Event();
        $event->title = 'Тестовый концерт';

        $venue = new Venue();
        $venue->name = 'Главный зал';

        $session = new Session();
        $session->starts_at = CarbonImmutable::parse('2026-11-20 19:30:00');
        $session->setRelation('venue', $venue);

        $row = new HallRow();
        $row->name = 'Ряд 7';

        $seat = new Seat();
        $seat->number = '12';
        $seat->label = 'Место 12';
        $seat->setRelation('row', $row);

        $ticket = new Ticket();
        $ticket->id = 77;
        $ticket->ticket_number = 'TCK-ABCD-001';
        $ticket->qr_payload = 'NB1.ticket-public-id.token.signature';
        $ticket->setRelation('order', $order);
        $ticket->setRelation('orderItem', $orderItem);
        $ticket->setRelation('event', $event);
        $ticket->setRelation('session', $session);
        $ticket->setRelation('seat', $seat);
        $ticket->setRelation('standingZone', null);

        $generator = new TicketGeneratorService();
        $data = $generator->generateTicketData($ticket);

        $this->assertSame('Тестовый концерт', $data['event_name']);
        $this->assertSame('20.11.2026 19:30', $data['event_date']);
        $this->assertSame('Ряд 7 / Место 12', $data['seats']);
        $this->assertEquals(125, $data['price']);
        $this->assertSame('NB1.ticket-public-id.token.signature', $data['qr_payload']);
        $this->assertArrayNotHasKey('qr_code', $data, 'do not claim a raster QR exists without a QR encoder');

        $renderedEmail = (new TicketPurchasedMail($ticket, $data))->render();
        $this->assertStringContainsString('Тестовый концерт', $renderedEmail);
        $this->assertStringContainsString('NB1.ticket-public-id.token.signature', $renderedEmail);

        $service = new NewsletterService($generator);
        $this->assertTrue($service->sendTicketNotification($ticket));
        Mail::assertSent(TicketPurchasedMail::class, static fn (TicketPurchasedMail $mail): bool =>
            $mail->ticket->id === 77 && $mail->ticketData['qr_payload'] === $ticket->qr_payload
        );
    }

    public function test_sold_count_excludes_unconverted_holds_and_only_active_holds_are_held(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $sessionId = 982301;
            $now = now();
            $inventoryId = DB::table('inventory_items')->insertGetId([
                'public_id' => (string) Str::ulid()->toBase32(),
                'session_id' => $sessionId,
                'type' => 'standing',
                'seat_id' => null,
                'standing_zone_id' => 982301,
                'price_amount' => 1000,
                'currency' => 'RUB',
                'capacity' => 10,
                'available_quantity' => 3,
                'status' => 'available',
                'metadata_json' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $holdId = DB::table('seat_holds')->insertGetId([
                'public_id' => (string) Str::ulid()->toBase32(),
                'inventory_item_id' => $inventoryId,
                'session_id' => $sessionId,
                'cart_id' => 982301,
                'quantity' => 2,
                'expires_at' => $now->copy()->addMinutes(10),
                'released_at' => null,
                'converted_at' => null,
                'created_at' => $now,
            ]);

            $repository = new InventoryItemRepository(new InventoryItem());
            $this->assertSame(5, $repository->getSoldCount($sessionId));
            $this->assertSame(1, $repository->getHeldCount($sessionId));

            DB::table('seat_holds')->where('id', $holdId)->update(['converted_at' => $now]);
            $this->assertSame(7, $repository->getSoldCount($sessionId));
            $this->assertSame(0, $repository->getHeldCount($sessionId));

            // An expired hold may remain unreleased until cleanup; it must not
            // inflate the sold metric, but it is no longer an active hold.
            DB::table('inventory_items')->where('id', $inventoryId)->update(['available_quantity' => 2]);
            DB::table('seat_holds')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'inventory_item_id' => $inventoryId,
                'session_id' => $sessionId,
                'cart_id' => 982302,
                'quantity' => 1,
                'expires_at' => $now->copy()->subMinute(),
                'released_at' => null,
                'converted_at' => null,
                'created_at' => $now->copy()->subMinutes(2),
            ]);
            $this->assertSame(7, $repository->getSoldCount($sessionId));
            $this->assertSame(0, $repository->getHeldCount($sessionId));
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
