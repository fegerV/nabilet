<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Сквозной гостевой прогон покупки через HTTP.
 *
 * Раньше цепочка была покрыта по звеньям (схема → сеанс, корзина → заказ,
 * платёж → билет, чек-ин), но ни один тест не проходил её целиком. Стыки —
 * именно то место, где в истории проекта случались 404/500: несовпадение
 * provider_payment_id при вебхуке, билет без qr_payload, недоступность билета
 * после реальной оплаты.
 */
final class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;
    use StaffAuth;

    private const CART_TOKEN = 'e2e-cart-token-1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nabilet.payment.demo_mode' => true,
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['127.0.0.1'],
            'nabilet.payment.yookassa.webhook_secret' => null,
        ]);
        Queue::fake();

        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => new ScriptedPaymentProvider());
    }

    public function test_a_guest_can_publish_a_hall_buy_a_ticket_and_check_in(): void
    {
        // 1. Организация и площадка со схемой — всё через прикладные HTTP-ручки.
        $organizationId = $this->seedOrganization();
        $this->actingAsStaff($organizationId);

        $venue = $this->postJson('/api/v1/venues', [
            'name' => 'Концертный зал E2E',
            'city' => 'Сургут',
        ])->assertCreated();
        $venueId = (int) $venue->json('data.id');
        $hallPublicId = (string) $venue->json('hall.public_id');

        $schemaVersionId = $this->publishSchema($hallPublicId);

        // 2. Мероприятие само создаёт основной сеанс и инвентарь из схемы.
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Концерт для сквозной проверки',
            'slug' => 'e2e-concert-' . Str::lower(Str::random(8)),
            'starts_at' => '2026-12-20 19:00:00',
            'venue_id' => $venueId,
            'schema_version_id' => $schemaVersionId,
        ])->assertCreated();
        $eventId = (int) $event->json('data.id');
        $sessionId = (int) DB::table('events')->where('id', $eventId)->value('primary_session_id');
        self::assertGreaterThan(0, $sessionId, 'событие обязано создать продаваемый сеанс');

        $this->postJson("/api/v1/events/{$eventId}/publish")->assertOk();

        // Продажи открыты: событие создаёт сеанс в scheduled, а касса требует
        // on_sale и попадания в окно продаж.
        $this->patchJson("/api/v1/sessions/{$sessionId}", [
            'event_id' => $eventId,
            'hall_id' => (int) DB::table('halls')->where('public_id', $hallPublicId)->value('id'),
            'starts_at' => '2026-12-20 19:00:00',
            'status' => 'on_sale',
            'sales_start_at' => now()->subDay()->toDateTimeString(),
            'sales_end_at' => now()->addMonth()->toDateTimeString(),
        ])->assertOk();

        // 3. Витрина видит место с ценой из опубликованной схемы.
        $inventory = $this->getJson("/api/v1/inventory?session_id={$sessionId}")
            ->assertOk()
            ->json('data.data');
        self::assertNotEmpty($inventory, 'схема не материализовала инвентарь');
        $inventoryPublicId = (string) $inventory[0]['public_id'];
        $inventoryNumericId = (int) DB::table('inventory_items')->where('public_id', $inventoryPublicId)->value('id');
        $totalMinor = (int) $inventory[0]['price_amount'];

        // 4. Гостевой холд и заказ.
        $headers = [CartToken::HEADER => self::CART_TOKEN];
        $cart = $this->postJson('/api/v1/carts', ['session_id' => $sessionId], $headers)
            ->assertCreated();
        $cartId = (string) $cart->json('data.id');
        $itemResponse = $this->postJson("/api/v1/carts/{$cartId}/items", [
            'inventory_item_id' => $inventoryNumericId,
            'quantity' => 1,
        ], $headers);
        self::assertSame(201, $itemResponse->status(), (string) $itemResponse->getContent());

        $checkout = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'e2e-buyer@example.com',
            'customer_email_confirmation' => 'e2e-buyer@example.com',
            'customer_name' => 'Тестовый покупатель',
        ], $headers)->assertOk();
        $orderPublicId = (string) $checkout->json('data.order_id');
        self::assertNotSame('', $orderPublicId);

        // 5. Платёж по HTTP: idempotency_key обязателен, корзина авторизует гостя.
        $payment = $this->postJson('/api/v1/payments', [
            'order_id' => $orderPublicId,
            'idempotency_key' => 'e2e-pay-' . $orderPublicId,
        ], $headers)->assertCreated();
        $providerPaymentId = (string) $payment->json('data.payment.provider_payment_id');
        self::assertNotSame('', $providerPaymentId);

        // 6. Вебхук с тем же provider_payment_id — самый частый стык расхождений.
        $this->postJson('/api/v1/webhooks/payment/yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => $providerPaymentId,
                'status' => 'succeeded',
                'amount' => [
                    'value' => number_format($totalMinor / 100, 2, '.', ''),
                    'currency' => 'RUB',
                ],
            ],
        ])->assertOk();

        self::assertSame('paid', (string) DB::table('orders')->where('public_id', $orderPublicId)->value('status'));
        self::assertSame('sold', (string) DB::table('inventory_items')->where('public_id', $inventoryPublicId)->value('status'));

        // 7. Билет с подписанным QR реально выписан вебхуком.
        $ticket = app(OrganizationContext::class)->withoutScope(
            fn (): ?Ticket => Ticket::query()->where('order_id', DB::table('orders')->where('public_id', $orderPublicId)->value('id'))->first()
        );
        self::assertNotNull($ticket);
        self::assertNotEmpty($ticket->qr_payload);

        $mine = $this->getJson('/api/v1/my-tickets', $headers)->assertOk();
        self::assertNotEmpty($mine->json('data'));

        // 8. Тот самый QR проходит чек-ин: не отдельная фикстура, а выданный билет.
        $device = $this->postJson('/api/v1/admin/checkin/devices', [
            'organization_id' => $organizationId,
            'name' => 'Входная группа E2E',
        ])->assertCreated();
        $deviceToken = (string) $device->json('data.token');
        self::assertNotSame('', $deviceToken);

        $this->withToken($deviceToken)->postJson('/api/v1/checkin/validate', [
            'qr_payload' => $ticket->qr_payload,
            'session_id' => $sessionId,
        ])->assertOk()->assertJsonPath('data.result', 'valid');

        $scan = $this->withToken($deviceToken)->postJson('/api/v1/checkin/use', [
            'qr_payload' => $ticket->qr_payload,
            'session_id' => $sessionId,
            'client_scan_id' => '11111111-2222-4333-8444-555555555555',
        ]);
        self::assertSame(200, $scan->status(), (string) $scan->getContent());
        $scan->assertJsonPath('data.result', 'valid');

        self::assertSame('used', (string) $ticket->fresh()->status);
        self::assertSame(1, (int) DB::table('ticket_scans')->where('ticket_id', $ticket->id)->count());
    }

    private function seedOrganization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'E2E организация',
            'slug' => 'e2e-' . Str::lower(Str::random(8)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function schemaPayload(): array
    {
        $seats = [];
        for ($i = 1; $i <= 2; $i += 1) {
            $seats[] = ['id' => "seat-{$i}", 'row' => 1, 'number' => $i, 'x' => $i * 24, 'y' => 20];
        }

        return [
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [[
                'name' => 'Партер',
                'priceMinor' => 5_000_000,
                'seats' => $seats,
            ]],
        ];
    }

    private function publishSchema(string $hallPublicId): int
    {
        $draft = $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/draft", [
            'payload' => $this->schemaPayload(),
        ])->assertSuccessful();
        $versionId = (int) $draft->json('data.id');
        self::assertGreaterThan(0, $versionId);

        $this->postJson("/api/v1/halls/{$hallPublicId}/schema-versions/{$versionId}/publish", ['base_revision' => 1])
            ->assertSuccessful();

        return $versionId;
    }
}
