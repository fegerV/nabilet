<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Путь записи платежей против реальной схемы.
 *
 * Модуль Payments писал `organization_id`, `method`, `webhook_url`, `metadata`,
 * `succeeded_at`, `failure_code`, `failure_message`, `failed_at` — ни одной из
 * этих колонок в `payments` нет. Здесь платёж действительно создаётся и результат
 * читается из базы.
 */
class PaymentWritePathTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;

    private OrderService $orders;
    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->app->make(OrderService::class);
        $this->payments = $this->app->make(PaymentService::class);
    }

    public function test_create_payment_writes_the_real_schema_columns(): void
    {
        $order = $this->pendingOrder(quantity: 2);

        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-123',
            'payment_url' => 'https://yookassa.ru/checkout/abc',
        ]);

        $row = DB::table('payments')->where('id', $payment->id)->first();

        $this->assertNotNull($row, 'payments row was not written');
        $this->assertSame('yookassa', $row->provider);
        $this->assertSame('yk-123', $row->provider_payment_id);
        $this->assertSame(2 * self::PRICE, (int) $row->amount);
        $this->assertSame('RUB', $row->currency);
        $this->assertSame(PaymentStateMachine::PENDING, $row->status);
        $this->assertSame('https://yookassa.ru/checkout/abc', $row->payment_url);
        $this->assertSame(26, strlen((string) $row->public_id));
        $this->assertNotSame('', (string) $row->idempotency_key, 'idempotency_key is NOT NULL in the schema');
        $this->assertNull($row->paid_at);
    }

    public function test_create_payment_moves_the_order_to_awaiting_payment(): void
    {
        $order = $this->pendingOrder();

        $this->payments->createPayment($order->id, ['provider' => 'yookassa']);

        // Без этого шага заказ навсегда остался бы в `pending`, а `pending → paid`
        // машина состояний запрещает — оплаченным он стать не мог.
        $this->assertSame(
            OrderStateMachine::AWAITING_PAYMENT,
            DB::table('orders')->where('id', $order->id)->value('status')
        );
    }

    public function test_create_payment_records_an_authorization_transaction(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->payments->createPayment($order->id, ['provider' => 'yookassa']);

        $transaction = DB::table('payment_transactions')->where('payment_id', $payment->id)->first();

        $this->assertNotNull($transaction);
        $this->assertSame('authorization', $transaction->type);
        $this->assertSame('pending', $transaction->status);
        // Колонка называется `payload_json`; `metadata` в таблице нет.
        $this->assertNotNull($transaction->created_at);
    }

    public function test_create_payment_is_idempotent_by_key(): void
    {
        $order = $this->pendingOrder();

        $first = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'idempotency_key' => 'retry-key-1',
        ]);

        $second = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'idempotency_key' => 'retry-key-1',
        ]);

        $this->assertSame($first->id, $second->id, 'a retry with the same key created a second payment');
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_webhook_succeeded_pays_the_payment_and_the_order(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-paid-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-paid-1', 'status' => 'succeeded'],
        ]);

        $paymentRow = DB::table('payments')->where('id', $payment->id)->first();
        $this->assertSame(PaymentStateMachine::SUCCEEDED, $paymentRow->status);
        $this->assertNotNull($paymentRow->paid_at, 'paid_at was not stamped (succeeded_at does not exist)');

        $orderRow = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame(OrderStateMachine::PAID, $orderRow->status);
        $this->assertSame('succeeded', $orderRow->payment_status);
        $this->assertNotNull($orderRow->paid_at);

        $this->assertSame(
            1,
            DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('type', 'capture')
                ->count()
        );

        $this->assertNotNull(
            DB::table('webhook_events')->where('event_name', 'payment.succeeded')->value('processed_at')
        );
    }

    public function test_a_replayed_webhook_does_not_pay_twice(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-paid-2',
        ]);

        $payload = [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-paid-2', 'status' => 'succeeded'],
        ];

        $this->payments->processWebhook('yookassa', $payload);
        $this->payments->processWebhook('yookassa', $payload);
        $this->payments->processWebhook('yookassa', $payload);

        // Три доставки — один платёж, одно списание, один заказ.
        $this->assertSame(
            1,
            DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('type', 'capture')
                ->count(),
            'a replayed webhook was processed more than once'
        );
        $this->assertSame(1, DB::table('webhook_events')->count());
        $this->assertSame(OrderStateMachine::PAID, DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_webhook_failed_stores_the_reason_in_metadata_json(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-failed-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.canceled',
            'object' => [
                'id' => 'yk-failed-1',
                'status' => 'canceled',
                'cancellation_details' => ['reason' => 'insufficient_funds', 'party' => 'yoo_money'],
            ],
        ]);

        $row = DB::table('payments')->where('id', $payment->id)->first();
        $this->assertSame(PaymentStateMachine::FAILED, $row->status);

        // Колонок `failure_code`/`failure_message`/`failed_at` нет — причина живёт
        // в `metadata_json`.
        $metadata = json_decode((string) $row->metadata_json, true);
        $this->assertSame('insufficient_funds', $metadata['failure']['code'] ?? null);
        $this->assertSame('yoo_money', $metadata['failure']['message'] ?? null);

        // Заказ НЕ оплачен: отказ не должен менять его статус.
        $this->assertNotSame(
            OrderStateMachine::PAID,
            DB::table('orders')->where('id', $order->id)->value('status')
        );
    }

    public function test_webhook_for_an_unknown_payment_is_rejected(): void
    {
        $this->expectException(DomainRuleViolation::class);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'does-not-exist', 'status' => 'succeeded'],
        ]);
    }

    public function test_refund_is_refused_for_a_payment_that_did_not_succeed(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->payments->createPayment($order->id, ['provider' => 'yookassa']);

        $this->configureYooKassa();

        $this->expectException(DomainRuleViolation::class);

        $this->payments->refundPayment($payment->fresh());
    }

    public function test_refund_writes_the_real_status_and_the_required_order_id(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-refund-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-refund-1', 'status' => 'succeeded'],
        ]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-777',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        $this->payments->refundPayment($payment->fresh(), self::PRICE, 'покупатель передумал');

        $refund = DB::table('refunds')->where('payment_id', $payment->id)->first();

        $this->assertNotNull($refund, 'refunds row was not written');
        // `refunds.order_id` объявлен NOT NULL — возврат привязан и к заказу.
        $this->assertSame($order->id, (int) $refund->order_id);
        $this->assertSame(self::PRICE, (int) $refund->amount);
        $this->assertSame('rf-777', $refund->provider_refund_id);
        // Статус из `ck_refunds_status`; `pending` этот CHECK нарушает.
        $this->assertSame(RefundStateMachine::PROCESSING, $refund->status);
        $this->assertSame(26, strlen((string) $refund->public_id));
    }

    public function test_webhook_endpoint_refuses_when_authentication_is_not_configured(): void
    {
        // Ни allowlist, ни секрет не заданы. «Нечем проверить» не означает
        // «проверка пройдена»: уведомление приниматься не должно.
        config([
            'nabilet.payment.yookassa.webhook_ip_allowlist' => [],
            'nabilet.payment.yookassa.webhook_secret' => null,
        ]);

        $response = $this->postJson('/api/v1/webhooks/payment/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-forged', 'status' => 'succeeded'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'WEBHOOK_NOT_AUTHENTICATED');
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_webhook_endpoint_rejects_a_source_address_outside_the_allowlist(): void
    {
        config([
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['203.0.113.10'],
            'nabilet.payment.yookassa.webhook_secret' => null,
        ]);

        // В тестах `$request->ip()` — 127.0.0.1, то есть вне allowlist.
        $response = $this->postJson('/api/v1/webhooks/payment/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-forged-2', 'status' => 'succeeded'],
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WEBHOOK_IP_NOT_ALLOWED');
    }

    public function test_webhook_endpoint_accepts_an_allowlisted_source(): void
    {
        config([
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['127.0.0.1'],
            'nabilet.payment.yookassa.webhook_secret' => null,
        ]);

        $order = $this->pendingOrder(quantity: 1);
        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-allow-1',
        ]);

        $response = $this->postJson('/api/v1/webhooks/payment/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-allow-1', 'status' => 'succeeded'],
        ]);

        $response->assertOk();
        $this->assertSame(OrderStateMachine::PAID, DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_payment_index_is_fail_closed_without_a_tenant_context(): void
    {
        $order = $this->pendingOrder();
        $this->payments->createPayment($order->id, ['provider' => 'yookassa']);

        // `GET /api/v1/payments` защищён `auth:api`, поэтому без токена — 401.
        // Это и есть первый барьер; второй — fail-closed скоуп внутри сервиса.
        $this->getJson('/api/v1/payments')->assertStatus(401);

        $empty = $this->payments->paginate([], 20);
        $this->assertSame(0, $empty->total(), 'payments were listed without an organization scope');
    }

    private function pendingOrder(int $quantity = 1): \Nabilet\Modules\Orders\Models\Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        return $this->orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => $quantity]],
        ]);
    }

    private function configureYooKassa(): void
    {
        config([
            'nabilet.payment.yookassa.shop_id' => 'test-shop',
            'nabilet.payment.yookassa.secret_key' => 'test-secret',
        ]);
    }
}
