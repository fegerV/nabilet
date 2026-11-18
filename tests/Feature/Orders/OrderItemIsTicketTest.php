<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Tickets\Services\TicketService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Заказ = билет: состояние билета пишется прямо в позицию заказа.
 *
 * Таблица tickets пока остаётся legacy-зеркалом, но новые продажи уже
 * несут QR и статус погашения в order_items — это позволяет в будущем
 * удалить tickets без потери истории.
 */
final class OrderItemIsTicketTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    public function test_issued_ticket_is_mirrored_into_the_order_item(): void
    {
        [$organizationId, , , $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $orders = app(OrderService::class);

        $order = $orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);
        $orders->markAwaitingPayment($order);
        $orders->markPaid($order->fresh());

        $tickets = app(TicketService::class)->issueTicketsForOrder($order->fresh());
        self::assertCount(1, $tickets);

        $item = DB::table('order_items')->where('order_id', $order->id)->first();

        self::assertSame($tickets[0]->ticket_number, $item->ticket_number);
        self::assertSame($tickets[0]->qr_token_hash, $item->qr_token_hash);
        self::assertSame($tickets[0]->qr_payload, $item->qr_payload);
        self::assertSame('issued', $item->ticket_status);
        self::assertSame(1, (int) $item->quantity);
        self::assertNotNull($item->event_id);
        self::assertNotNull($item->session_id);
        self::assertNotNull($item->issued_at);
    }
}
