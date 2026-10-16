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
}
