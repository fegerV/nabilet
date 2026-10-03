<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Настоящая проверка пути записи заказа против реальной схемы.
 *
 * Существовавшие до этого тесты (`OrderApiTest`) проверяли только то, что
 * эндпоинт отвечает одним из кодов 200/401/403. Этого недостаточно: код,
 * который писал `session_id`, `customer_name`, `metadata`, `subtotal` и
 * `total_price`, успешно проходил такой тест, потому что ни один запрос
 * создания заказа в нём не выполнялся. Здесь заказ действительно создаётся,
 * а результат читается из базы.
 */
class OrderWritePathTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 5_000_000; // 50 000,00 в минорных единицах

    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(OrderService::class);
    }

    public function test_create_order_writes_the_real_schema_columns(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '+77001234567',
            'items' => [
                ['inventory_item_id' => $inventoryId, 'quantity' => 2],
            ],
        ]);

        $row = DB::table('orders')->where('id', $order->id)->first();

        $this->assertNotNull($row, 'orders row was not written');
        $this->assertSame(2 * self::PRICE, (int) $row->subtotal_amount);
        $this->assertSame(0, (int) $row->discount_amount);
        $this->assertSame(0, (int) $row->fee_amount);
        $this->assertSame(2 * self::PRICE, (int) $row->total_amount);
        $this->assertSame('buyer@example.com', $row->customer_email);
        $this->assertSame('+77001234567', $row->customer_phone);
        $this->assertSame(OrderStateMachine::PENDING, $row->status);
        $this->assertSame('pending', $row->payment_status);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->cancelled_at);
        $this->assertSame('RUB', $row->currency);

        // public_id — CHAR(26); UUID сюда бы не влез.
        $this->assertSame(26, strlen((string) $row->public_id));

        // order_number уникален и человекочитаем: NB-YYYYMMDD-XXXXXXXX
        $this->assertMatchesRegularExpression('/^NB-\d{8}-[0-9A-Z]{8}$/', (string) $row->order_number);
        $this->assertLessThanOrEqual(64, strlen((string) $row->order_number));
    }

    public function test_create_order_writes_order_items_with_the_title_snapshot(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3, eventTitle: 'Ромео и Джульетта');

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $item = DB::table('order_items')->where('order_id', $order->id)->first();

        $this->assertNotNull($item, 'order_items row was not written');
        $this->assertSame($inventoryId, (int) $item->inventory_item_id);
        $this->assertSame(1, (int) $item->quantity);
        $this->assertSame(self::PRICE, (int) $item->unit_price);
        $this->assertSame(self::PRICE, (int) $item->total_amount);
        // NOT NULL в схеме — и это весь смысл снимка: билет помнит, что купили,
        // даже если событие потом переименуют.
        $this->assertSame('Ромео и Джульетта', $item->event_title_snapshot);
        $this->assertNotNull($item->created_at);

        // У order_items нет updated_at — модель не должна его писать.
        $this->assertFalse(
            collect(DB::select('select column_name from information_schema.columns where table_name = ?', ['order_items']))
                ->pluck('column_name')
                ->contains('updated_at'),
            'order_items gained an updated_at column; OrderItem::UPDATED_AT = null must be revisited'
        );
    }

    public function test_create_order_reserves_inventory(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 5);

        $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 3]],
        ]);

        $this->assertSame(2, (int) DB::table('inventory_items')->where('id', $inventoryId)->value('available_quantity'));
    }

    public function test_price_comes_from_inventory_not_from_the_request(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        // Клиент присылает «свою» цену в 1 минорную единицу.
        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'attacker@example.com',
            'items' => [
                ['inventory_item_id' => $inventoryId, 'quantity' => 1, 'price' => 1],
            ],
        ]);

        $this->assertSame(
            self::PRICE,
            (int) DB::table('orders')->where('id', $order->id)->value('total_amount'),
            'the client-supplied price was trusted — a seat can be bought for 1 minor unit'
        );
    }

    public function test_create_order_refuses_more_seats_than_available(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $this->expectException(DomainRuleViolation::class);

        try {
            $this->service->createOrder([
                'organization_id' => $organizationId,
                'customer_email' => 'buyer@example.com',
                'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 2]],
            ]);
        } finally {
            // Ни заказ, ни позиции не должны остаться: транзакция откатывается.
            $this->assertSame(0, DB::table('orders')->count());
            $this->assertSame(0, DB::table('order_items')->count());
            $this->assertSame(
                1,
                (int) DB::table('inventory_items')->where('id', $inventoryId)->value('available_quantity'),
                'inventory was consumed by a failed order'
            );
        }
    }

    public function test_cancel_releases_inventory_and_stamps_cancelled_at(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 4);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 3]],
        ]);

        $this->assertSame(1, (int) DB::table('inventory_items')->where('id', $inventoryId)->value('available_quantity'));

        $cancelled = $this->service->cancelOrder($order->fresh(), 'покупатель передумал');

        $row = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame(OrderStateMachine::CANCELLED, $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertSame(OrderStateMachine::CANCELLED, $cancelled->status);
        $this->assertSame(
            4,
            (int) DB::table('inventory_items')->where('id', $inventoryId)->value('available_quantity'),
            'cancelling did not return the seats to sale'
        );
    }

    public function test_paid_order_cannot_be_cancelled(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $this->service->markAwaitingPayment($order->fresh());
        $this->service->markPaid($order->fresh());

        $this->expectException(InvalidStateTransitionError::class);

        try {
            $this->service->cancelOrder($order->fresh());
        } finally {
            // Место НЕ вернулось в продажу: деньги не возвращены, значит место
            // всё ещё принадлежит покупателю.
            $this->assertSame(
                2,
                (int) DB::table('inventory_items')->where('id', $inventoryId)->value('available_quantity')
            );
        }
    }

    public function test_mark_paid_sets_the_real_status_and_payment_status(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $awaiting = $this->service->markAwaitingPayment($order->fresh());
        $this->assertSame(OrderStateMachine::AWAITING_PAYMENT, $awaiting->status);

        $this->service->markPaid($awaiting->fresh());

        $row = DB::table('orders')->where('id', $order->id)->first();
        // `paid`, а не `completed`: значения `completed` нет в ck_orders_status.
        $this->assertSame(OrderStateMachine::PAID, $row->status);
        $this->assertSame('succeeded', $row->payment_status);
        $this->assertNotNull($row->paid_at);
    }

    public function test_mark_awaiting_payment_is_idempotent(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $first = $this->service->markAwaitingPayment($order->fresh());
        $second = $this->service->markAwaitingPayment($first->fresh());

        $this->assertSame(OrderStateMachine::AWAITING_PAYMENT, $second->status);
    }

    /**
     * `pending → paid` запрещён намеренно: заказ, который никто не пытался
     * оплатить, не должен становиться оплаченным от одной записи в базу.
     */
    public function test_an_untouched_order_cannot_be_marked_paid(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $this->expectException(InvalidStateTransitionError::class);

        $this->service->markPaid($order->fresh());
    }

    public function test_apply_payment_is_idempotent(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $paymentId = $this->seedSucceededPayment($order->id, self::PRICE);
        $payment = \Nabilet\Modules\Payments\Models\Payment::query()->findOrFail($paymentId);

        $this->service->applyPayment($order->fresh(), $payment);
        $this->assertSame(OrderStateMachine::PAID, DB::table('orders')->where('id', $order->id)->value('status'));

        // Повторный вебхук: тот же вызов не должен ни упасть, ни сломать статус.
        $this->service->applyPayment($order->fresh(), $payment);
        $this->assertSame(OrderStateMachine::PAID, DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_apply_payment_does_not_pay_a_partially_covered_order(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        $order = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 2]],
        ]);

        // Оплачена только половина суммы заказа.
        $payment = \Nabilet\Modules\Payments\Models\Payment::query()
            ->findOrFail($this->seedSucceededPayment($order->id, self::PRICE));

        $this->service->applyPayment($order->fresh(), $payment);

        $this->assertSame(
            OrderStateMachine::PENDING,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'a half-paid order was marked paid'
        );
    }

    public function test_revenue_report_counts_paid_orders_only(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        $paid = $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'paid@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 2]],
        ]);
        $this->service->markAwaitingPayment($paid->fresh());
        $this->service->markPaid($paid->fresh());

        $this->service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'pending@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $revenue = $this->service->getRevenueReport(
            $organizationId,
            now()->subDay()->toDateTimeString(),
            now()->addDay()->toDateTimeString(),
        );

        $this->assertSame(2 * self::PRICE, (int) $revenue);
    }

    public function test_orders_api_rejects_a_payload_without_customer_email(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 3);

        // Ответ приходит в конверте §66, а не в формате Laravel:
        //   {"error":{"code":"VALIDATION_ERROR","details":{"fields":{"customer_email":[…]}}}}
        // Поэтому `assertJsonValidationErrors()` здесь не подходит — он ищет ключ
        // `errors`, которого в этом контракте нет.
        $response = $this->postJson('/api/v1/orders', [
            'organization_id' => $organizationId,
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey(
            'customer_email',
            $response->json('error.details.fields'),
            'the §66 envelope did not report the missing customer_email'
        );
    }

    /**
     * Создаёт минимальную цепочку FK: организация → событие → площадка → зал →
     * версия схемы → сессия → единица склада.
     */
    use SellableSeatFixtures;
}
