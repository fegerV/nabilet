<?php

declare(strict_types=1);

namespace Tests\Feature\Kiosk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;
use Nabilet\Modules\Kiosk\Models\KioskSale;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Продажа через киоск идёт тем же путём, что и web-чекаут.
 *
 * Проверяется главное: повтор после обрыва связи (тот же client_sale_id)
 * НЕ создаёт второй заказ/билет, а выключенные продажи не пускают устройство
 * к платёжному контуру. статус догоняет заказ командой kiosks:sync-sales.
 */
final class KioskSaleTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;
    use StaffAuth;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml не задаёт демо-режим: без этой строки тест проходил только
        // на машине с PAYMENT_DEMO_MODE=true в .env, а в CI фаза 2 киоска падала
        // «платёжный провайдер не настроен» и отдавала 409 вместо 201.
        config(['nabilet.payment.demo_mode' => true]);
    }

    /** @return array{int, string, int, int, int} [orgId, token, deviceId, sessionId, inventoryId] */
    private function setupKiosk(bool $salesEnabled): array
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(10);
        $this->actingAsStaff($organizationId);

        $response = $this->postJson('/api/v1/admin/kiosks', [
            'organization_id' => $organizationId,
            'name' => 'Киоск в залах',
            'sales_enabled' => $salesEnabled,
        ])->assertStatus(201);

        return [
            $organizationId,
            (string) $response->json('data.token'),
            (int) $response->json('data.device.id'),
            (int) $sessionId,
            (int) $inventoryItemId,
        ];
    }

    public function test_kiosk_creates_a_sale_and_replays_the_same_client_sale_id(): void
    {
        [, $token, $deviceId, $sessionId, $inventoryItemId] = $this->setupKiosk(true);
        $clientSaleId = (string) Str::uuid();

        $payload = [
            'client_sale_id' => $clientSaleId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'customer_email' => 'buyer@example.test',
        ];

        $first = $this->withToken($token)->postJson('/api/v1/kiosk/sales', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.status', SalePolicy::STATUS_AWAITING_PAYMENT);

        $orderId = $first->json('data.order_public_id');
        $this->assertNotNull($orderId);
        $this->assertSame(1, KioskSale::query()->count());

        $second = $this->withToken($token)->postJson('/api/v1/kiosk/sales', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.order_public_id', $orderId);

        $this->assertSame(
            $first->json('data.client_sale_id'),
            $second->json('data.client_sale_id')
        );
        $this->assertSame(1, KioskSale::query()->count(), 'повтор не создал вторую продажу');
    }

    public function test_sales_disabled_kiosk_cannot_start_a_sale(): void
    {
        [, $token, , $sessionId, $inventoryItemId] = $this->setupKiosk(false);

        $this->withToken($token)->postJson('/api/v1/kiosk/sales', [
            'client_sale_id' => (string) Str::uuid(),
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
        ])->assertStatus(409)->assertJsonPath('error.code', 'KIOSK_SALES_DISABLED');

        $this->assertSame(0, KioskSale::query()->count());
    }

    public function test_sale_status_is_visible_to_the_device_and_not_to_another(): void
    {
        [, $token, , $sessionId, $inventoryItemId] = $this->setupKiosk(true);
        $clientSaleId = (string) Str::uuid();

        $this->withToken($token)->postJson('/api/v1/kiosk/sales', [
            'client_sale_id' => $clientSaleId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
        ])->assertStatus(201);

        $this->withToken($token)->getJson('/api/v1/kiosk/sales/' . $clientSaleId)
            ->assertOk()
            ->assertJsonPath('data.client_sale_id', $clientSaleId);

        $this->withToken('nbks_other')->getJson('/api/v1/kiosk/sales/' . $clientSaleId)
            ->assertStatus(401);
    }

    public function test_sync_command_promotes_paid_sale_and_attaches_qr(): void
    {
        [, $token, $deviceId, $sessionId, $inventoryItemId] = $this->setupKiosk(true);
        $clientSaleId = (string) Str::uuid();

        $response = $this->withToken($token)->postJson('/api/v1/kiosk/sales', [
            'client_sale_id' => $clientSaleId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
        ])->assertStatus(201);

        $sale = KioskSale::query()->where('client_sale_id', $clientSaleId)->firstOrFail();

        // Имитируем webhook: заказ оплачен, билет выпущен с подписанным QR.
        DB::table('orders')->where('id', $sale->order_id)->update([
            'status' => OrderStateMachine::PAID,
        ]);
        DB::table('order_items')->where('order_id', $sale->order_id)->limit(1)->update([
            'ticket_status' => 'issued',
            'qr_payload' => 'NB1.TEST.test.sig',
        ]);

        // В кроне нет HTTP-контекста: снимаем его, чтобы тест поймал
        // TenantContextMissingError на скоупированной модели.
        app(OrganizationContext::class)->clear();
        $this->artisan('kiosks:sync-sales')->assertExitCode(0);

        $sale->refresh();
        $this->assertSame(SalePolicy::STATUS_ISSUED, $sale->status);
        $this->assertSame('NB1.TEST.test.sig', $sale->qr_payload);

        // Экран видит QR только после выпуска.
        $this->withToken($token)->getJson('/api/v1/kiosk/sales/' . $clientSaleId)
            ->assertOk()
            ->assertJsonPath('data.qr_payload', 'NB1.TEST.test.sig');
    }

    public function test_sync_command_finishes_paid_sale_after_late_ticket_issue(): void
    {
        [, $token, , $sessionId, $inventoryItemId] = $this->setupKiosk(true);
        $clientSaleId = (string) Str::uuid();

        $this->withToken($token)->postJson('/api/v1/kiosk/sales', [
            'client_sale_id' => $clientSaleId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
        ])->assertStatus(201);

        $sale = KioskSale::query()->where('client_sale_id', $clientSaleId)->firstOrFail();
        DB::table('orders')->where('id', $sale->order_id)->update(['status' => OrderStateMachine::PAID]);

        // Билет ещё не выпущен: продажа переходит в paid, но НЕ должна
        // выпасть из следующего прогона синка (баг: whereIn без PAID).
        app(OrganizationContext::class)->clear();
        $this->artisan('kiosks:sync-sales')->assertExitCode(0);
        $this->assertSame(SalePolicy::STATUS_PAID, $sale->fresh()->status);

        DB::table('order_items')->where('order_id', $sale->order_id)->limit(1)->update([
            'ticket_status' => 'issued',
            'qr_payload' => 'NB1.LATE.test.sig',
        ]);
        app(OrganizationContext::class)->clear();
        $this->artisan('kiosks:sync-sales')->assertExitCode(0);

        $sale->refresh();
        $this->assertSame(SalePolicy::STATUS_ISSUED, $sale->status);
        $this->assertSame('NB1.LATE.test.sig', $sale->qr_payload);
    }

    public function test_replay_returns_payment_url_from_the_stored_payment(): void
    {
        [, $token, , $sessionId, $inventoryItemId] = $this->setupKiosk(true);
        $clientSaleId = (string) Str::uuid();
        $payload = [
            'client_sale_id' => $clientSaleId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
        ];

        $this->withToken($token)->postJson('/api/v1/kiosk/sales', $payload)->assertStatus(201);

        $sale = KioskSale::query()->where('client_sale_id', $clientSaleId)->firstOrFail();
        DB::table('payments')->where('id', $sale->payment_id)->update([
            'payment_url' => 'https://pay.example.test/confirm/123',
        ]);

        $this->withToken($token)->postJson('/api/v1/kiosk/sales', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.payment_url', 'https://pay.example.test/confirm/123');
    }

    public function test_revoked_kiosk_cannot_sell(): void
    {
        [, $token, $deviceId, $sessionId, $inventoryItemId] = $this->setupKiosk(true);
        $this->actingAsStaff($this->organizationIdFor($deviceId));
        $this->postJson('/api/v1/admin/kiosks/' . $deviceId . '/revoke')->assertOk();

        $this->withToken($token)->postJson('/api/v1/kiosk/sales', [
            'client_sale_id' => (string) Str::uuid(),
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
        ])->assertStatus(401)->assertJsonPath('error.code', 'KIOSK_NOT_ACTIVE');
    }

    private function organizationIdFor(int $deviceId): int
    {
        return (int) DB::table('kiosk_devices')->where('id', $deviceId)->value('organization_id');
    }
}
