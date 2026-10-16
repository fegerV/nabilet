<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Напоминание о незавершённой оплате (`orders:remind-unpaid`).
 *
 * Брошенный ЗАКАЗ — единственное брошенное, у которого есть контакт
 * (`customer_email` остаётся в строке после checkout). Одно письмо на заказ,
 * и только когда он «полежал» час: раньше — шум для человека, который просто
 * открыл платёжную страницу.
 */
final class UnpaidOrderReminderTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private OrderService $orders;
    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->app->make(OrderService::class);
        $this->payments = $this->app->make(PaymentService::class);

        config([
            'nabilet.payment.yookassa.shop_id' => 'test-shop',
            'nabilet.payment.yookassa.secret_key' => 'test-secret',
        ]);
    }

    /** Оформленный и брошенный заказ с платежом и указанным возрастом. */
    private function awaitingOrder(int $ageHours, string $email = 'buyer@example.test'): object
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 2);

        $order = $this->orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => $email,
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-rem-' . $order->id,
            'payment_url' => 'https://yookassa.ru/checkout/rem-' . $order->id,
        ]);

        // Возраст — прямым UPDATE: тестируется свип, а не таймер.
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => now()->subHours($ageHours)->toDateTimeString(),
        ]);

        return $order->fresh();
    }

    public function test_reminds_once_with_amount_and_payment_link(): void
    {
        Mail::fake();

        $order = $this->awaitingOrder(ageHours: 2);

        $this->artisan('orders:remind-unpaid');

        Mail::assertSent(TemplateMail::class, 1);

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) use ($order): bool {
            $this->assertTrue($mail->hasTo($order->customer_email));
            $this->assertStringContainsString((string) ($order->order_number ?? $order->public_id), $mail->subjectLine);
            $this->assertStringContainsString('yookassa.ru/checkout', (string) $mail->textBody);
            $this->assertStringContainsString('50000.00', (string) $mail->textBody);

            return true;
        });

        $this->assertNotNull(
            DB::table('orders')->where('id', $order->id)->value('reminder_sent_at'),
            'флаг напоминания обязан ставиться после отправки'
        );
    }

    public function test_reminds_only_once_per_order(): void
    {
        Mail::fake();

        $this->awaitingOrder(ageHours: 2);

        $this->artisan('orders:remind-unpaid');
        $this->artisan('orders:remind-unpaid');

        Mail::assertSent(TemplateMail::class, 1);
    }

    public function test_fresh_order_is_not_reminded(): void
    {
        Mail::fake();

        $this->awaitingOrder(ageHours: 0);

        $this->artisan('orders:remind-unpaid');

        Mail::assertNotSent(TemplateMail::class);
    }

    public function test_order_without_email_is_not_reminded(): void
    {
        Mail::fake();

        $order = $this->awaitingOrder(ageHours: 2);

        DB::table('orders')->where('id', $order->id)->update(['customer_email' => '']);

        $this->artisan('orders:remind-unpaid');

        Mail::assertNotSent(TemplateMail::class);
    }

    public function test_paid_order_is_not_reminded(): void
    {
        $order = $this->awaitingOrder(ageHours: 2);

        // Успешный вебхук переводит заказ в paid: напоминать «оплатите»
        // оплаченному — недопустимое враньё.
        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-rem-' . $order->id,
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        Mail::fake();

        $this->artisan('orders:remind-unpaid');

        Mail::assertNotSent(TemplateMail::class);
    }
}
