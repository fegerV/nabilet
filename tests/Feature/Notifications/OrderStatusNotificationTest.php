<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Webhooks\Jobs\DispatchOrderWebhookJob;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

class OrderStatusNotificationTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    public function test_status_observer_dispatches_mail_and_webhook_after_order_transition(): void
    {
        Bus::fake();

        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);
        $order = app(OrderService::class)->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        self::assertSame(OrderStateMachine::PENDING, $order->status);

        app(OrderService::class)->markAwaitingPayment($order);

        Bus::assertDispatched(SendOrderNotificationJob::class, function (SendOrderNotificationJob $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->expectedStatus === OrderStateMachine::AWAITING_PAYMENT
                && $job->afterCommit === true;
        });

        Bus::assertDispatched(DispatchOrderWebhookJob::class, function (DispatchOrderWebhookJob $job) use ($order, $organizationId): bool {
            return $job->eventName === 'order.awaiting_payment'
                && $job->organizationId === $organizationId
                && $job->payload['order_id'] === $order->id
                && $job->payload['status'] === OrderStateMachine::AWAITING_PAYMENT
                && ! array_key_exists('customer_email', $job->payload)
                && $job->afterCommit === true;
        });
    }
}
