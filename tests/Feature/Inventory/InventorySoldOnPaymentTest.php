<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Оплаченное место обязано стать `sold` в РЕАЛЬНОМ пути покупки.
 *
 * ДЕФЕКТ, который этот файл фиксирует.
 *
 * `PaymentService::markInventorySoldForOrder()` проверяет переход по
 * `InventoryItemStateMachine` (аудит R-12: безусловная запись `sold` превращала
 * в проданное место в статусе `disabled`). Проверка сама по себе верна, но
 * словарь машины не совпадал со словарём, которым пишет корзина.
 *
 * `CartItemService::addItem()` после списания ставит:
 *
 *     available_quantity === 0  →  'sold_out'
 *     иначе, если 'available'  →  'held'
 *
 * Место имеет `capacity = 1`, поэтому после добавления в корзину
 * `available_quantity` становится нулём и статус — `sold_out`, а НЕ `held`.
 * `sold_out` в `InventoryItemStateMachine` отсутствовал, поэтому
 * `can('sold_out', 'sold')` возвращал `false`, и `markInventorySoldForOrder()`
 * молча уходила в ветку `continue` с `Log::warning`.
 *
 * Следствие: в реальном пути «корзина → checkout → оплата» место навсегда
 * оставалось `sold_out`. В `sold` его переводил только тест, создающий заказ
 * напрямую через `OrderService` в обход корзины (`pendingOrder()` в
 * `PaymentInitiationGuardsTest`) — там место остаётся `available`, и переход
 * `available → sold` разрешён. То есть проданное место не попадало ни в
 * отчётность по `sold`, ни в запрет на повторную продажу: `OrderService`
 * сбрасывает `sold_out` в `available` при отмене заказа так же охотно, как
 * `held`.
 *
 * Тесты идут через HTTP и через сервис оплаты — то есть по тому же пути, что и
 * покупатель, а не в обход корзины.
 */
class InventorySoldOnPaymentTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.payment.demo_mode' => true]);

        $this->payments = $this->app->make(PaymentService::class);

        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => new ScriptedPaymentProvider());
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return [CartToken::HEADER => self::TOKEN];
    }

    private function statusOf(int $inventoryItemId): string
    {
        return (string) DB::table('inventory_items')->where('id', $inventoryItemId)->value('status');
    }

    /**
     * Единственное место в продаже: `capacity = 1`, поэтому добавление в корзину
     * исчерпывает вместимость и переводит позицию в `sold_out`.
     *
     * @return array{0: int, 1: int} [orderId, inventoryItemId]
     */
    private function buyThroughCart(): array
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: self::PRICE,
        );

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->authHeaders())->assertStatus(201);

        $response = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Покупатель',
        ], $this->authHeaders())->assertStatus(200);

        $orderId = (int) DB::table('orders')
            ->where('public_id', $response->json('data.order_id'))
            ->value('id');

        return [$orderId, $inventoryItemId];
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

    /**
     * ПРЕДУСЛОВИЕ, а не проверка продукта: корзина ставит именно `sold_out`.
     *
     * Если этот тест покраснеет, значит корзина перестала исчерпывать
     * вместимость, и следующий тест проверяет уже не тот путь.
     */
    public function test_adding_the_last_unit_to_the_cart_sets_sold_out(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: self::PRICE,
        );

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->authHeaders())->assertStatus(201);

        $this->assertSame(
            0,
            (int) DB::table('inventory_items')->where('id', $inventoryItemId)->value('available_quantity'),
        );

        $this->assertSame(
            'sold_out',
            $this->statusOf($inventoryItemId),
            'корзина исчерпала вместимость, но позиция не стала sold_out'
        );
    }

    /** Оплата места, купленного через корзину, обязана довести его до `sold`. */
    public function test_a_paid_seat_bought_through_the_cart_becomes_sold(): void
    {
        Log::spy();

        [$orderId, $inventoryItemId] = $this->buyThroughCart();

        $this->assertSame(
            'sold_out',
            $this->statusOf($inventoryItemId),
            'предусловие теста: корзина обязана довести позицию до sold_out'
        );

        $this->paySucceeded($orderId);

        $this->assertSame(
            'paid',
            DB::table('orders')->where('id', $orderId)->value('status'),
            'предусловие теста: заказ обязан стать paid'
        );

        $this->assertSame(
            'sold',
            $this->statusOf($inventoryItemId),
            'оплаченное место осталось в предоплатном статусе — проданного места нет ни в отчётности по sold, ни в запрете на повторную продажу'
        );

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Место в standing-зоне, где после продажи остались свободные, ведёт себя
     * как раньше: `held` → `sold`. Контроль к предыдущему тесту — исправление не
     * должно сломать путь, который работал.
     */
    public function test_a_paid_standing_zone_with_capacity_left_still_becomes_sold(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 5,
            priceAmount: self::PRICE,
        );

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->authHeaders())->assertStatus(201);

        $this->assertSame(
            'held',
            $this->statusOf($inventoryItemId),
            'остались свободные места — позиция обязана быть held, а не sold_out'
        );

        $response = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Покупатель',
        ], $this->authHeaders())->assertStatus(200);

        $orderId = (int) DB::table('orders')
            ->where('public_id', $response->json('data.order_id'))
            ->value('id');

        $this->paySucceeded($orderId);

        $this->assertSame('sold', $this->statusOf($inventoryItemId));
    }
}
