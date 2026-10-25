<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Domain\TransactionType;
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
        $this->assertSame(TransactionType::AUTHORIZATION, $transaction->type);
        $this->assertSame(PaymentStateMachine::PENDING, $transaction->status);
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
            'object' => [
                'id' => 'yk-paid-1',
                'status' => 'succeeded',
                // ЮKassa всегда присылает сумму; вебхук сверяет её с заказом
                // (PaymentSettlement), поэтому «уведомление без суммы» — это
                // отдельный негативный сценарий, а не норма.
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
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
                ->where('type', TransactionType::CAPTURE)
                ->count()
        );

        $this->assertNotNull(
            DB::table('webhook_events')->where('event_name', 'payment.succeeded')->value('processed_at')
        );

        // Цепочка «оплата → билет» проверяется ЗДЕСЬ, а не только в Unit-тестах.
        // Раньше выпуск билетов был отключён (в `PaymentService` зависимость
        // объявлялась как `?TicketService $ticketService = null`, а контейнер
        // подставлял именно `null`), и этот тест ничего не замечал: он смотрел
        // только на `payment_transactions`. Теперь оплата обязана оставить билет
        // с непустым QR — `event_id`/`session_id` в `tickets` объявлены NOT NULL,
        // поэтому их отсутствие здесь и было той самой ошибкой 1048.
        $ticket = DB::table('tickets')->where('order_id', $order->id)->first();

        $this->assertNotNull($ticket, 'paid order must have issued tickets');
        $this->assertSame('issued', $ticket->status);
        $this->assertNotNull($ticket->event_id, 'tickets.event_id is NOT NULL');
        $this->assertNotNull($ticket->session_id, 'tickets.session_id is NOT NULL');
        $this->assertNotNull($ticket->qr_payload);
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
            'object' => [
                'id' => 'yk-paid-2',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ];

        $this->payments->processWebhook('yookassa', $payload);
        $this->payments->processWebhook('yookassa', $payload);
        $this->payments->processWebhook('yookassa', $payload);

        // Три доставки — один платёж, одно списание, один заказ.
        $this->assertSame(
            1,
            DB::table('payment_transactions')
                ->where('payment_id', $payment->id)
                ->where('type', TransactionType::CAPTURE)
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
            'event' => 'payment.failed',
            'object' => [
                'id' => 'yk-failed-1',
                'status' => 'failed',
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

        // Заказ НЕ оплачен: отказ не должен менять его статус на paid.
        $this->assertNotSame(
            OrderStateMachine::PAID,
            DB::table('orders')->where('id', $order->id)->value('status')
        );
    }

    /**
     * A12: `payment.canceled` — ОТДЕЛЬНОЕ терминальное состояние, а не синоним
     * `failed`.
     *
     * Этот тест появился потому, что предыдущий (сверху) до правки отправлял
     * `payment.canceled`, но ожидал `failed`. Так было верно ДО A12, когда оба
     * события шли в один обработчик. После разделения обработчиков тест остался
     * прежним — и остался незамеченным ровно потому, что Feature-набор не
     * запускался (см. `tests/run.php`). Здесь контракт зафиксирован явно:
     * отменённый платёж отличим от неудачного, и заказ уходит в `payment_failed`
     * (машина разрешает ретрай другой картой).
     */
    public function test_webhook_canceled_is_a_distinct_terminal_state_from_failed(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-canceled-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.canceled',
            'object' => [
                'id' => 'yk-canceled-1',
                'status' => 'canceled',
                'cancellation_details' => ['reason' => 'payment_canceled', 'party' => 'yoo_money'],
            ],
        ]);

        $status = DB::table('payments')->where('id', $payment->id)->value('status');

        $this->assertSame(PaymentStateMachine::CANCELED, $status);
        $this->assertNotSame(
            PaymentStateMachine::FAILED,
            $status,
            'A12: отмена не должна схлопываться в failed — иначе отменённый платёж неотличим от неудачного'
        );

        $this->assertSame(
            OrderStateMachine::PAYMENT_FAILED,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'Отмена обязана двигать заказ: иначе он навсегда висит в awaiting_payment'
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
            'object' => [
                'id' => 'yk-refund-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
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
            'object' => [
                'id' => 'yk-allow-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
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

    /**
     * R-01: уведомление `succeeded` с суммой, НЕ совпадающей с заказом.
     *
     * Раньше такое уведомление принималось молча: заказ становился `paid`, а
     * билет выпускался — то есть система выдавала билет за неоплаченную сумму, а
     * расхождение с выпиской провайдера всплывало только при ручной сверке.
     * Теперь решение принимает доменный `PaymentSettlement`: поступление
     * фиксируется (деньги у провайдера реально взяты), но заказ НЕ оплачивается,
     * билет НЕ выпускается, а деньги уходят в автовозврат.
     */
    public function test_webhook_succeeded_with_a_mismatched_amount_is_refused_and_refunded(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-mismatch-1',
        ]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-mismatch-1',
                'status' => 'succeeded',
                'amount' => ['value' => '1.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-mismatch-1',
                'status' => 'succeeded',
                // 1 ₽ вместо 50 000 ₽.
                'amount' => ['value' => '1.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->assertNotSame(
            OrderStateMachine::PAID,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'заказ оплачен по сумме, которая не совпадает с его итогом'
        );

        $this->assertSame(
            0,
            DB::table('tickets')->where('order_id', $order->id)->count(),
            'билет выпущен за неоплаченную сумму'
        );

        // Поступление зафиксировано: книги обязаны сходиться с провайдером.
        $this->assertSame(
            PaymentStateMachine::SUCCEEDED,
            DB::table('payments')->where('id', $payment->id)->value('status'),
        );

        // И деньги возвращаются автоматически, а не «зависают».
        $this->assertSame(1, DB::table('refunds')->where('payment_id', $payment->id)->count());

        // Уведомление помечено обработанным: провайдер не должен повторять его вечно.
        $this->assertNotNull(
            DB::table('webhook_events')->where('event_name', 'payment.succeeded')->value('processed_at'),
        );
    }

    /**
     * R-05: деньги пришли по УЖЕ ТЕРМИНАЛЬНОМУ заказу (места перепроданы).
     *
     * Раньше заказ оставался `expired`, платёж — `pending`, билет не выпускался,
     * и деньги покупателя просто исчезали из поля зрения системы. Теперь это
     * терминальный исход: поступление фиксируется и сразу возвращается, а
     * терминальный статус заказа НЕ переписывается (перехода `expired →
     * refunded` не существует).
     */
    public function test_webhook_succeeded_for_a_terminal_order_is_refunded_instead_of_issued(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-terminal-1',
        ]);

        // Заказ протух, пока покупатель платил.
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStateMachine::EXPIRED]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-terminal-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-terminal-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->assertSame(
            OrderStateMachine::EXPIRED,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'терминальный заказ не должен воскрешаться'
        );
        $this->assertSame(0, DB::table('tickets')->where('order_id', $order->id)->count());
        $this->assertSame(
            PaymentStateMachine::SUCCEEDED,
            DB::table('payments')->where('id', $payment->id)->value('status'),
        );
        $this->assertSame(1, DB::table('refunds')->where('payment_id', $payment->id)->count());
    }

    /**
     * Уведомление `succeeded` вообще без суммы — сверять нечего, значит отказ
     * (fail-closed), а не молчаливая оплата «на ноль».
     */
    public function test_webhook_succeeded_without_an_amount_is_refused(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-noamount-1',
        ]);

        $this->configureYooKassa();
        Http::fake(['*/refunds' => Http::response([
            'id' => 'rf-noamount-1',
            'status' => 'succeeded',
            'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
        ], 200)]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-noamount-1', 'status' => 'succeeded'],
        ]);

        $this->assertNotSame(
            OrderStateMachine::PAID,
            DB::table('orders')->where('id', $order->id)->value('status')
        );
        $this->assertSame(0, DB::table('tickets')->where('order_id', $order->id)->count());
    }

    /**
     * R-07: `refund.succeeded` обязан закрыть возврат.
     *
     * Раньше `processWebhook` не знал событий `refund.*`: уведомление уходило в
     * `default` → RuntimeException → 500, claim откатывался, провайдер повторял
     * доставку бесконечно, а возврат навсегда оставался `processing` без
     * `completed_at`.
     */
    public function test_refund_succeeded_webhook_finalizes_the_refund(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-refund-fin-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-refund-fin-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-fin-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        $this->payments->refundPayment($payment->fresh(), null, 'возврат');

        $refundId = (int) DB::table('refunds')->where('payment_id', $payment->id)->value('id');
        $this->assertSame(
            RefundStateMachine::PROCESSING,
            DB::table('refunds')->where('id', $refundId)->value('status')
        );

        // Провайдер сообщает исход: object.id — id ВОЗВРАТА, платёж — в object.payment_id.
        $this->payments->processWebhook('yookassa', [
            'event' => 'refund.succeeded',
            'object' => [
                'id' => 'rf-fin-1',
                'payment_id' => 'yk-refund-fin-1',
                'status' => 'succeeded',
            ],
        ]);

        $refund = DB::table('refunds')->where('id', $refundId)->first();
        $this->assertSame(RefundStateMachine::SUCCEEDED, $refund->status);
        $this->assertNotNull($refund->completed_at, 'completed_at не проставлен — возврат навсегда висит processing');
    }

    /**
     * R-06: возврат доступен оператору через HTTP.
     *
     * `RefundService` был реализован полностью, но не вызывался ни одним
     * маршрутом — система физически не умела вернуть деньги, хотя диалог отмены
     * в админке это обещал.
     */
    public function test_refund_endpoint_requires_staff_and_refunds_the_order(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-refund-api-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-refund-api-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-api-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        // Маршрут под `auth:api` + `admin`: анонимный возврат невозможен.
        $this->postJson("/api/v1/orders/{$order->id}/refund")->assertStatus(401);

        $this->actingAsAdmin((int) $order->organization_id);

        $this->postJson("/api/v1/orders/{$order->id}/refund", [
            'reason' => 'мероприятие отменено',
        ])->assertOk();

        $refund = DB::table('refunds')->where('payment_id', $payment->id)->first();

        $this->assertNotNull($refund, 'возврат не создан — RefundService снова недостижим из HTTP');
        $this->assertSame(self::PRICE, (int) $refund->amount);
        $this->assertSame(RefundStateMachine::PROCESSING, $refund->status);

        // RefundLedger выводит статус заказа из денег: полный возврат → refunded.
        $this->assertSame(
            OrderStateMachine::REFUNDED,
            DB::table('orders')->where('id', $order->id)->value('status'),
        );
    }

    /**
     * R-07: решение о ДОПУСТИМОЙ сумме возврата принимает RefundLedger, а не
     * копия арифметики внутри RefundService.
     *
     * Раньше `RefundService::refund()` держал СВОИ три проверки суммы
     * (`$refundAmount <= 0`, `$totalRefunded + $refundAmount > $payment->amount`,
     * `$totalRefunded >= $payment->amount`), дублирующие `RefundLedger::evaluate()`,
     * а сам `evaluate()` вызывался НИЖЕ — его отказы были недостижимы. Проверка
     * «never refund more than was paid» держалась не им. Этот тест фиксирует
     * контракт: переплата сверх остатка отвергается кодом REFUND_EXCEEDS_BALANCE,
     * который генерирует ТОЛЬКО RefundLedger (сервис лишь переводит его причину в
     * код контракта). Вернуть назад локальную копию, не сломав тест, нельзя — её
     * код не совпадёт с REFUND_EXCEEDS_BALANCE.
     */
    public function test_refund_over_balance_is_rejected_with_refund_exceeds_balance(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-refund-bal-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-refund-bal-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-bal-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        $this->actingAsAdmin((int) $order->organization_id);

        // Просим вернуть на 1 копейку больше оплаченного. RefundLedger должен
        // отказать по REFUND_EXCEEDS_BALANCE — это решение домена, а не сервиса.
        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => self::PRICE + 1,
            'reason' => 'тест переплаты',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'REFUND_EXCEEDS_BALANCE');

        // Никакой записи возврата не создано — деньги не двинулись.
        $this->assertSame(
            0,
            (int) DB::table('refunds')->where('payment_id', $payment->id)->count(),
            'возврат сверх остатка всё же записался — решение ушло не в RefundLedger',
        );
    }

    /**
     * R-07 (обратная сторона): корректная частичная сумма ДОЛЖНА пройти, и именно
     * RefundLedger выводит статус заказа из остатка денег (partially_refunded, а не
     * refunded). Если сервис снова начнёт считать статус руками — тест упадёт.
     */
    public function test_partial_refund_within_balance_is_allowed_and_marks_partially_refunded(): void
    {
        $order = $this->pendingOrder(quantity: 1);
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-refund-part-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-refund-part-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->configureYooKassa();
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-part-1',
                'status' => 'succeeded',
                'amount' => ['value' => '25000.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        $this->actingAsAdmin((int) $order->organization_id);

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => intdiv(self::PRICE, 2),
            'reason' => 'частичный возврат',
        ])->assertOk();

        $refund = DB::table('refunds')->where('payment_id', $payment->id)->first();

        $this->assertNotNull($refund, 'частичный возврат не создан — RefundLedger не пропустил валидную сумму');
        $this->assertSame(intdiv(self::PRICE, 2), (int) $refund->amount);
        $this->assertSame(RefundStateMachine::PROCESSING, $refund->status);

        // Остаток > 0 → RefundLedger решает partially_refunded, а не refunded.
        $this->assertSame(
            OrderStateMachine::PARTIALLY_REFUNDED,
            DB::table('orders')->where('id', $order->id)->value('status'),
        );
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

    /**
     * Администратор организации — то, что требует middleware `admin`
     * (`StaffRole::isStaff()` читает `user_roles`, а не колонку в `users`).
     */
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
}
