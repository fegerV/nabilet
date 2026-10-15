<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Письмо с билетом должно доходить до покупателя — и поддаваться исправлению.
 *
 * ДЕФЕКТ, который этот файл закрывает.
 *
 * Письмо уходит на `orders.customer_email` — адрес, введённый покупателем при
 * оформлении. Если в нём опечатка, билет не приходил НИКОГДА: письмо уходило в
 * никуда, исправить адрес было нечем, повторной отправки не существовало. Это
 * не гипотетический случай: при гостевом чекауте опечатка в адресе — самый
 * частый способ потерять билет, и заметить её покупателю было негде.
 *
 * Две части фикса и проверяются здесь:
 *
 *   1. ЧЕКАУТ требует повторить адрес (`customer_email_confirmation`,
 *      правило `same:customer_email`) — опечатка ловится до оплаты;
 *   2. `POST /api/v1/my-tickets/resend` пересылает письмо и позволяет при этом
 *      исправить адрес. Доступ — по гостевому токену корзины (`X-Cart-Token`),
 *      ровно как у `GET /my-tickets`: витрина гостевая, `user_id` у заказа NULL.
 *
 * Границы доступа проверяются отдельно: чужой заказ обязан быть неотличим от
 * несуществующего (404, не 403), иначе эндпоинт становится оракулом
 * существования заказов, а заодно — релеем писем на чужой адрес.
 */
class TicketEmailResendTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const FOREIGN_TOKEN = '99999999-8888-7777-6666-555555555555';

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.payment.demo_mode' => true]);

        // Письма не отправляем: проверяем, что задание поставлено в очередь.
        // `QUEUE_CONNECTION=sync` в phpunit.xml выполнил бы его сразу, и тогда
        // тест зависел бы от наличия шаблона `order.paid` в базе.
        Queue::fake();

        $this->payments = $this->app->make(PaymentService::class);

        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => new ScriptedPaymentProvider());
    }

    /** @return array<string, string> */
    private function cartHeaders(string $token = self::TOKEN): array
    {
        return [CartToken::HEADER => $token];
    }

    /**
     * Создать заказ гостя через корзину. Оплата — по флагу: тесту про
     * неоплаченный заказ нужен именно `awaiting_payment`.
     *
     * @return array{0: int, 1: string} [orderId, orderPublicId]
     */
    private function placeOrder(string $token = self::TOKEN, string $email = 'buyer@example.com', bool $pay = true): array
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: self::PRICE,
        );

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->cartHeaders($token))->assertStatus(201);

        $publicId = (string) $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => $email,
            'customer_email_confirmation' => $email,
            'customer_name' => 'Покупатель',
        ], $this->cartHeaders($token))->assertStatus(200)->json('data.order_id');

        $orderId = (int) DB::table('orders')->where('public_id', $publicId)->value('id');

        if ($pay) {
            $this->paySucceeded($orderId);
        }

        return [$orderId, $publicId];
    }

    private function paySucceeded(int $orderId): void
    {
        $total = (int) DB::table('orders')->where('id', $orderId)->value('total_amount');
        $providerPaymentId = 'scripted_paid_' . Str::random(8);

        $this->payments->createPayment($orderId, [
            'provider' => 'yookassa',
            'provider_payment_id' => $providerPaymentId,
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => $providerPaymentId,
                'status' => 'succeeded',
                'amount' => [
                    'value' => number_format($total / 100, 2, '.', ''),
                    'currency' => 'RUB',
                ],
            ],
        ]);
    }

    private function emailOf(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('customer_email');
    }

    // ── 1. Чекаут: защита от опечатки ────────────────────────────────────

    /** Повтор адреса, не совпадающий с самим адресом, — отказ ДО оплаты. */
    public function test_checkout_rejects_a_confirmation_that_differs_from_the_address(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: self::PRICE,
        );

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->cartHeaders())->assertStatus(201);

        $response = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_email_confirmation' => 'buyer@exmaple.com',
        ], $this->cartHeaders())->assertStatus(422);

        $fields = (array) $response->json('error.details.fields');

        $this->assertArrayHasKey(
            'customer_email_confirmation',
            $fields,
            'расхождение адреса и его повтора обязано быть ошибкой поля, а не общей плашкой',
        );

        // Заказ не создан: проверка стоит ДО оформления.
        $this->assertSame(0, DB::table('orders')->count());
    }

    /** Совпадающий повтор — не препятствие: правило не ломает обычную покупку. */
    public function test_checkout_accepts_a_matching_confirmation(): void
    {
        [$orderId] = $this->placeOrder();

        $this->assertGreaterThan(0, $orderId);
        $this->assertSame('buyer@example.com', $this->emailOf($orderId));
    }

    // ── 2. Повторная отправка и исправление адреса ───────────────────────

    /** Главный сценарий: покупатель исправляет опечатку и получает письмо. */
    public function test_a_guest_can_resend_the_ticket_to_a_corrected_address(): void
    {
        [$orderId, $publicId] = $this->placeOrder(email: 'buyer@exmaple.com');

        $before = Queue::pushed(SendOrderNotificationJob::class)->count();

        $this->postJson('/api/v1/my-tickets/resend', [
            'order' => $publicId,
            'email' => 'buyer@example.com',
        ], $this->cartHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.email', 'buyer@example.com');

        $this->assertSame(
            'buyer@example.com',
            $this->emailOf($orderId),
            'исправленный адрес обязан сохраниться в заказе — иначе письмо уйдёт по старому',
        );

        Queue::assertPushed(
            SendOrderNotificationJob::class,
            $before + 1,
        );

        // Заказ тот же: исправление адреса не создаёт второй заказ.
        $this->assertSame(1, DB::table('orders')->where('public_id', $publicId)->count());
    }

    /** Без адреса в теле письмо уходит на сохранённый — «отправить ещё раз». */
    public function test_resending_without_an_address_keeps_the_stored_one(): void
    {
        [$orderId, $publicId] = $this->placeOrder(email: 'buyer@example.com');

        $this->postJson('/api/v1/my-tickets/resend', ['order' => $publicId], $this->cartHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'buyer@example.com');

        $this->assertSame('buyer@example.com', $this->emailOf($orderId));
    }

    /** Письмо шлётся только по оплаченному заказу: у остальных билета нет. */
    public function test_resending_is_refused_for_an_unpaid_order(): void
    {
        [$orderId, $publicId] = $this->placeOrder(email: 'buyer@example.com', pay: false);

        // `CartCheckoutService` создаёт заказ в `pending`; `awaiting_payment`
        // наступает позже — при инициализации платежа. Тесту важно, что статус
        // не `paid`, то есть билет ещё не выпущен.
        $this->assertSame(
            'pending',
            (string) DB::table('orders')->where('id', $orderId)->value('status'),
            'предусловие теста: неоплаченный заказ обязан быть в pending',
        );

        $this->postJson('/api/v1/my-tickets/resend', [
            'order' => $publicId,
            'email' => 'attacker@example.com',
        ], $this->cartHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_NOT_PAID');

        $this->assertSame(
            'buyer@example.com',
            $this->emailOf($orderId),
            'отказ обязан произойти ДО записи адреса',
        );
    }

    // ── 3. Границы доступа ───────────────────────────────────────────────

    /** Чужой токен корзины — 404: заказ неотличим от несуществующего. */
    public function test_a_foreign_cart_token_cannot_resend_someone_elses_ticket(): void
    {
        [$orderId, $publicId] = $this->placeOrder();

        $this->postJson('/api/v1/my-tickets/resend', [
            'order' => $publicId,
            'email' => 'attacker@example.com',
        ], $this->cartHeaders(self::FOREIGN_TOKEN))
            ->assertStatus(404);

        $this->assertSame(
            'buyer@example.com',
            $this->emailOf($orderId),
            'чужой запрос не имеет права менять адрес доставки билета',
        );
    }

    /** Без токена — тоже 404: capability не предъявлена. */
    public function test_resending_without_a_cart_token_is_refused(): void
    {
        [$orderId, $publicId] = $this->placeOrder();

        $this->postJson('/api/v1/my-tickets/resend', ['order' => $publicId])
            ->assertStatus(404);

        $this->assertSame('buyer@example.com', $this->emailOf($orderId));
    }

    /** Короткий токен отвергается так же: энтропия — часть проверки. */
    public function test_a_short_cart_token_is_refused(): void
    {
        [$orderId, $publicId] = $this->placeOrder();

        $this->postJson('/api/v1/my-tickets/resend', ['order' => $publicId], $this->cartHeaders('short-token'))
            ->assertStatus(404);

        $this->assertSame('buyer@example.com', $this->emailOf($orderId));
    }
}
