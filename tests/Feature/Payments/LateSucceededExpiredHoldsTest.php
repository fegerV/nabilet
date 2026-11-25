<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Domain\TransactionType;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * D-2 аудита платежей: деньги пришли, а холд уже снят sweeper'ом.
 *
 * Раньше вебхук бросал ConflictError, откатывал claim и уходил в бесконечную
 * доставку, а заказ оставался списанным без билета и без возврата. Теперь это
 * терминальный исход: поступление фиксируется, заказ НЕ оплачивается, билеты не
 * выпускаются, автоматически оформляется возврат.
 */
final class LateSucceededExpiredHoldsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const TOKEN = 'late-hold-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.payment.demo_mode' => true]);

        // Возврат идёт через провайдера — без скриптового шлюза тест уходил бы
        // в реальный HTTP к ЮKassa, и компенсационная запись маскировала бы
        // главный сценарий.
        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => new ScriptedPaymentProvider());
    }

    public function test_a_late_success_with_an_expired_hold_records_a_refund_instead_of_issuing_tickets(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $orderId = $this->placePaidOrder($sessionId, $inventoryItemId);
        $cartId = (int) DB::table('orders')->where('id', $orderId)->value('cart_id');
        self::assertGreaterThan(0, $cartId, 'предусловие: заказ через корзину');

        $payment = $this->app->make(PaymentService::class)->createPayment($orderId, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-late-' . Str::random(6),
        ]);

        // Sweeper уже вернул место: холд просрочен далеко за grace-окном.
        $affected = DB::table('seat_holds')
            ->where('cart_id', $cartId)
            ->update(['expires_at' => now()->subMinutes(30)->toDateTimeString()]);
        self::assertGreaterThan(0, $affected, 'предусловие: активный холд должен быть');

        $payload = $this->succeededPayload((string) $payment->provider_payment_id, (int) DB::table('orders')->where('id', $orderId)->value('total_amount'));
        $this->app->make(PaymentService::class)->processWebhook('yookassa', $payload);

        $paymentRow = DB::table('payments')->where('id', $payment->id)->first();
        self::assertSame(PaymentStateMachine::SUCCEEDED, $paymentRow->status);

        $orderRow = DB::table('orders')->where('id', $orderId)->first();
        self::assertNotSame(OrderStateMachine::PAID, $orderRow->status, 'деньги пришли без живой брони — заказ не оплачиваем');
        self::assertSame(0, (int) DB::table('tickets')->where('order_id', $orderId)->count());

        $refund = DB::table('refunds')->where('payment_id', $payment->id)->first();
        self::assertNotNull($refund, 'возврат не оформлен — деньги зависли');
        self::assertStringContainsString('seat_holds_expired', (string) $refund->reason);
        self::assertContains($refund->status, [RefundStateMachine::REQUESTED, RefundStateMachine::PROCESSING, RefundStateMachine::SUCCEEDED]);

        $capture = DB::table('payment_transactions')
            ->where('payment_id', $payment->id)
            ->where('type', TransactionType::CAPTURE)
            ->first();
        self::assertNotNull($capture);
        self::assertStringContainsString('seat_holds_expired', (string) $capture->payload_json);
    }

    public function test_a_replayed_late_success_does_not_create_a_second_refund(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $orderId = $this->placePaidOrder($sessionId, $inventoryItemId);
        $cartId = (int) DB::table('orders')->where('id', $orderId)->value('cart_id');
        $payments = $this->app->make(PaymentService::class);
        $payment = $payments->createPayment($orderId, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-replay-' . Str::random(6),
        ]);

        DB::table('seat_holds')->where('cart_id', $cartId)
            ->update(['expires_at' => now()->subMinutes(30)->toDateTimeString()]);

        $payload = $this->succeededPayload((string) $payment->provider_payment_id, (int) DB::table('orders')->where('id', $orderId)->value('total_amount'));
        $payments->processWebhook('yookassa', $payload);
        $payments->processWebhook('yookassa', $payload);

        self::assertSame(1, (int) DB::table('refunds')->where('payment_id', $payment->id)->count());
        self::assertSame(0, (int) DB::table('tickets')->where('order_id', $orderId)->count());
    }

    /** @return array<string, mixed> */
    private function succeededPayload(int|string $providerPaymentId, int $totalMinor): array
    {
        return [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => (string) $providerPaymentId,
                'status' => 'succeeded',
                'amount' => [
                    'value' => number_format($totalMinor / 100, 2, '.', ''),
                    'currency' => 'RUB',
                ],
            ],
        ];
    }

    private function placePaidOrder(int $sessionId, int $inventoryItemId): int
    {
        $headers = [CartToken::HEADER => self::TOKEN];
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $headers)->assertCreated();

        $checkout = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'late@example.com',
            'customer_email_confirmation' => 'late@example.com',
            'customer_name' => 'Покупатель',
        ], $headers)->assertOk();

        return (int) DB::table('orders')->where('public_id', $checkout->json('data.order_id'))->value('id');
    }
}
