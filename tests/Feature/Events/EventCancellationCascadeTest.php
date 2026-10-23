<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Services\EventPublicationService;
use Nabilet\Modules\Orders\Services\EventOrderRefunder;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Каскад «отмена события → возвраты → отзыв билетов» (аудиты D-4/S11, R-08).
 *
 * Прежде отмена события с проданными билетами была заперта политикой
 * HAS_SOLD_UNITS без какого-либо settle-first инструмента: билеты оставались
 * валидными на несостоявшееся мероприятие, деньги — у провайдера, а обход был
 * только прямым SQL мимо машин состояний.
 */
final class EventCancellationCascadeTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000; // 50 000.00

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

    public function test_cancelling_with_refund_settles_paid_orders_and_revokes_tickets(): void
    {
        [, $eventId, , $inventoryItemId] = $this->seedSellableSeat(available: 2);
        $event = Event::query()->findOrFail($eventId);
        $event->update(['status' => 'published']);

        $order = $this->orders->createOrder([
            'organization_id' => (int) $event->organization_id,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);

        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-cascade-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-cascade-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->assertSame(OrderStateMachine::PAID, $order->fresh()->status);
        $ticket = Ticket::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($ticket, 'оплаченный заказ обязан иметь билет');
        $this->assertSame('issued', $ticket->status);

        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-cascade-1',
                'status' => 'succeeded',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
            ]),
        ]);

        $outcome = $this->app->make(EventOrderRefunder::class)->cancelEventWithRefunds(
            $event->refresh(),
            $this->app->make(EventPublicationService::class),
            'Отмена мероприятия организатором',
        );

        // Событие отменено только ПОСЛЕ того, как деньги ушли покупателю.
        $this->assertSame(1, $outcome['refunded']);
        $this->assertSame([], $outcome['refund_failures']);
        $this->assertSame('cancelled', $event->fresh()->status);

        // Заказ уведён в refunded через RefundLedger, а не руками.
        $this->assertSame(OrderStateMachine::REFUNDED, $order->fresh()->status);

        $this->assertSame(
            1,
            (int) DB::table('refunds')->where('payment_id', $payment->id)->count(),
            'возврат обязан быть зафиксирован строкой refunds'
        );

        // Билеты аннулированы в статус refunded — CheckinEvaluator его отвергает,
        // QR перестаёт проходить check-in (это и есть эффект отзыва).
        $this->assertSame('refunded', (string) $ticket->fresh()->status);
    }

    public function test_plain_cancel_still_refuses_while_money_is_outstanding(): void
    {
        [, $eventId, , $inventoryItemId] = $this->seedSellableSeat(available: 2);
        $event = Event::query()->findOrFail($eventId);
        $event->update(['status' => 'published']);

        $order = $this->orders->createOrder([
            'organization_id' => (int) $event->organization_id,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);

        $this->payments->createPayment($order->id, ['provider' => 'yookassa']);
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStateMachine::PAID]);

        // Без возвратов обычная отмена обязана стоять: выдача билета без
        // возвращённых денег — именно то, от чего защищает политика.
        $this->expectException(ConflictError::class);

        $this->app->make(EventPublicationService::class)->cancel($event->refresh());
    }

    /**
     * Неоплаченный заказ не считается проданным — и не запирает отмену.
     *
     * Дефект, который здесь закрывается: `soldUnits()` фильтровал
     * `NOT IN ('cancelled','refunded')`, то есть считал обязательством любой
     * заказ, по которому не сделали возврат, — включая `pending`,
     * `awaiting_payment` и `expired`. Корзина, брошенная на десятой минуте,
     * навсегда запирала отмену события. Запирала и `cancelEventWithRefunds()`:
     * он возвращает деньги (их нет), а потом всё равно спрашивает политику, и
     * та видит «продано». Сообщение при отказе («сначала возвраты») указывало
     * на возвраты, которых не существует.
     *
     * Проверяется на `expired`, а не на `pending`: просроченный заказ — это
     * уже не «покупатель на странице оплаты», а брошенная корзина, по которой
     * `StaleOrderExpirer` вернул места в продажу. Заметь, что
     * `OrderStateMachine::occupyingInventory()` `expired` исключает и сейчас —
     * места снова на витрине, а заказ продолжал считаться проданным.
     */
    public function test_an_expired_order_does_not_count_as_sold(): void
    {
        [, $eventId, , $inventoryItemId] = $this->seedSellableSeat(available: 2);
        $event = Event::query()->findOrFail($eventId);
        $event->update(['status' => 'published']);

        $this->orders->createOrder([
            'organization_id' => (int) $event->organization_id,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);

        // Просрочен и переведён в expired: местa вернулись в продажу.
        DB::table('orders')->update(['status' => OrderStateMachine::EXPIRED]);

        $this->app->make(EventPublicationService::class)->cancel($event->refresh());

        $this->assertSame('cancelled', $event->fresh()->status);
    }

    /**
     * Тот же незапертый путь, но через «отменить с возвратами».
     *
     * Именно здесь отказ был особенно неудобен: единственный разрешённый
     * способ отмены события с продажами сам упирался в политику, потому что
     * возвращать было некому, а «продано» оставалось.
     */
    public function test_cancel_with_refunds_succeeds_when_only_expired_orders_exist(): void
    {
        [, $eventId, , $inventoryItemId] = $this->seedSellableSeat(available: 2);
        $event = Event::query()->findOrFail($eventId);
        $event->update(['status' => 'published']);

        $this->orders->createOrder([
            'organization_id' => (int) $event->organization_id,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);

        DB::table('orders')->update(['status' => OrderStateMachine::EXPIRED]);

        $outcome = $this->app->make(EventOrderRefunder::class)->cancelEventWithRefunds(
            $event->refresh(),
            $this->app->make(EventPublicationService::class),
            'Отмена мероприятия организатором'
        );

        $this->assertSame(0, $outcome['refunded']);
        $this->assertSame('cancelled', $event->fresh()->status);
    }
}
