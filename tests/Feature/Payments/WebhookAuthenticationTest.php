<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\WebhookSignatureVerifier;
use Nabilet\Modules\System\Support\ProductionSafetyAudit;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Аутентификация входящих вебхуков (аудит платежей R-04, R-04a, R-04b).
 *
 * Три дефекта одного слоя защиты:
 *  - `*` в IP-allowlist означал «любой адрес», то есть выключал единственную
 *    проверку, на которую опирается ЮKassa (она не подписывает уведомления);
 *  - HMAC считался по ПЕРЕКОДИРОВАННОМУ JSON, а не по сырому телу;
 *  - секрет читался из `config('payments.*')`, а файла `config/payments.php` в
 *    проекте нет — то есть настроенный в `.env` секрет не подключался никогда.
 */
class WebhookAuthenticationTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PAYLOAD_RAW = '{"event": "payment.succeeded", "object": {"id": "yk-raw-1", "status": "succeeded", "amount": {"value": "50000.00", "currency": "RUB"}}}';

    private OrderService $orders;
    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->app->make(OrderService::class);
        $this->payments = $this->app->make(PaymentService::class);
    }

    public function test_a_wildcard_ip_allowlist_does_not_authenticate_anyone(): void
    {
        config([
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['*'],
            'nabilet.payment.yookassa.webhook_secret' => null,
        ]);

        $response = $this->postJson('/api/v1/webhooks/payment/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-wildcard', 'status' => 'succeeded'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'WEBHOOK_NOT_AUTHENTICATED');
        $this->assertSame(0, DB::table('payments')->count(), 'Уведомление с `*` в allowlist не должно приниматься');
    }

    public function test_a_star_entry_is_dropped_from_a_mixed_allowlist(): void
    {
        config([
            // `*` рядом с настоящим адресом не должен «открыть» список.
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['*', '203.0.113.10'],
            'nabilet.payment.yookassa.webhook_secret' => null,
        ]);

        $response = $this->postJson('/api/v1/webhooks/payment/yookassa', [
            'event' => 'payment.succeeded',
            'object' => ['id' => 'yk-mixed', 'status' => 'succeeded'],
        ]);

        // Тестовый адрес — 127.0.0.1, его в списке нет.
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WEBHOOK_IP_NOT_ALLOWED');
    }

    /**
     * Подпись считается по СЫРОМУ телу: провайдер подписывает те байты, которые
     * отправил. Здесь тело содержит пробелы, поэтому его перекодировка из
     * декодированного массива даёт другую строку — и старая реализация
     * отвергла бы корректную подпись.
     */
    public function test_the_signature_is_computed_over_the_raw_body(): void
    {
        config([
            'nabilet.payment.yookassa.webhook_ip_allowlist' => [],
            'nabilet.payment.yookassa.webhook_secret' => 'webhook-secret-value',
        ]);

        // Платёж должен существовать: иначе 404 «неизвестный платёж» не отличить
        // от отказа аутентификации.
        $this->paymentFor('yk-raw-1');

        $rawSignature = hash_hmac('sha256', self::PAYLOAD_RAW, 'webhook-secret-value');

        $this->call('POST', '/api/v1/webhooks/payment/yookassa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $rawSignature,
        ], self::PAYLOAD_RAW)->assertOk();
    }

    public function test_a_signature_over_the_re_encoded_body_is_rejected(): void
    {
        config([
            'nabilet.payment.yookassa.webhook_ip_allowlist' => [],
            'nabilet.payment.yookassa.webhook_secret' => 'webhook-secret-value',
        ]);

        $this->paymentFor('yk-raw-1');

        // Канонизированная форма — НЕ то, что подписал отправитель.
        $canonical = json_encode(
            json_decode(self::PAYLOAD_RAW, true),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertNotSame(self::PAYLOAD_RAW, $canonical, 'Фикстура должна отличаться от канонической формы');

        $this->call('POST', '/api/v1/webhooks/payment/yookassa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $canonical, 'webhook-secret-value'),
        ], self::PAYLOAD_RAW)->assertStatus(403);
    }

    /**
     * Регресс R-04b: секрет должен читаться из `nabilet.payment.*`. Раньше ветка
     * `config('payments.*')` всегда возвращала `null`, и `verify()` не мог
     * отказать даже теоретически.
     */
    public function test_the_configured_secret_is_actually_used(): void
    {
        config(['nabilet.payment.yookassa.webhook_secret' => 'shh']);

        $verifier = app(WebhookSignatureVerifier::class);
        $raw = '{"a":1}';

        $this->assertTrue($verifier->verify('yookassa', ['a' => 1], hash_hmac('sha256', $raw, 'shh'), [], $raw));
        $this->assertFalse($verifier->verify('yookassa', ['a' => 1], 'not-a-signature', [], $raw));
    }

    public function test_the_signature_verifier_reports_a_missing_secret(): void
    {
        config(['nabilet.payment.stripe.webhook_secret' => null]);

        $this->expectException(DomainRuleViolation::class);

        app(WebhookSignatureVerifier::class)->verify('stripe', [], 'signature', []);
    }

    /** Production-сторож обязан заметить `*` в allowlist вебхуков. */
    public function test_the_production_guard_flags_a_wildcard_webhook_allowlist(): void
    {
        config([
            'nabilet.payment.providers' => ['yookassa'],
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['*'],
        ]);

        // Правила живут в `ProductionSafetyAudit` (провайдер только решает,
        // когда падать) — рефлексия же по провайдеру осталась от времён, когда
        // код проверок жил внутри него.
        $method = new \ReflectionMethod(ProductionSafetyAudit::class, 'wildcardWebhookAllowlists');

        $this->assertSame(['YOOKASSA_WEBHOOK_IP_ALLOWLIST'], $method->invoke(new ProductionSafetyAudit()));
    }

    public function test_the_production_guard_stays_quiet_for_a_real_allowlist(): void
    {
        config([
            'nabilet.payment.providers' => ['yookassa'],
            'nabilet.payment.yookassa.webhook_ip_allowlist' => ['185.32.0.0/24'],
        ]);

        $method = new \ReflectionMethod(ProductionSafetyAudit::class, 'wildcardWebhookAllowlists');

        $this->assertSame([], $method->invoke(new ProductionSafetyAudit()));
    }

    /**
     * Заказ + платёж с заданным `provider_payment_id` — чтобы вебхук находил
     * платёж и 200/403 означали именно результат проверки подписи, а не 404.
     */
    private function paymentFor(string $providerPaymentId): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        $order = $this->orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => $providerPaymentId,
        ]);
    }
}
