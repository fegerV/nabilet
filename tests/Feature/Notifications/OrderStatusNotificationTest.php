<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Notifications\Mail\TemplateMail;
use Nabilet\Modules\Notifications\Services\OrderNotificationData;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketTemplate;
use Nabilet\Modules\Tickets\Services\TicketService;
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

    /**
     * Сквозная проверка: оплаченный заказ → джоба → письмо с билетом и макетом.
     *
     * ПОЧЕМУ ЭТО НУЖНО ОТДЕЛЬНО ОТ ТЕСТА НА OrderNotificationData
     *
     * `OrderNotificationData` можно проверить в изоляции, но покупатель получает
     * письмо не от него, а от этой джобы, и между ними лежит ещё выбор шаблона
     * письма в БД (`notification_templates`), подстановка переменных и рендер.
     * Дефект в любом из этих звеньев даёт ровно тот исход, которого мы
     * избегаем: письмо уходит, а билета в нём нет. Здесь проверяется, что билет
     * доезжает до ГОТОВОГО тела письма.
     *
     * Палитра макета выбрана нестандартной (`#e11d48`) намеренно: иначе
     * «макет доехал» было бы неотличимо от «карточка отрисовалась по умолчанию».
     */
    public function test_the_paid_email_really_carries_the_ticket_and_the_assigned_template(): void
    {
        Mail::fake();

        // Джобу по смене статуса гасим: она ушла бы `afterCommit()` сразу после
        // `markPaid()`, то есть ДО выпуска билетов — и проверяла бы письмо без
        // билетов. Билеты выпускает платёжный контур, а не смена статуса.
        Bus::fake();

        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        [$organizationId, $eventId, , $inventoryId] = $this->seedSellableSeat(available: 1);

        $templateId = (int) TicketTemplate::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Сквозной макет',
            'format' => 'mobile',
            'width' => 400,
            'height' => 600,
            'template_json' => [
                'backgroundColor' => '#0b1220',
                'elements' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'fill' => '#e11d48']],
            ],
            'active' => true,
        ])->id;

        DB::table('events')->where('id', $eventId)->update(['ticket_template_id' => $templateId]);

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order->fresh());
        $order = $service->markPaid($order->fresh());

        // Обсервер обязан был поставить письмо в очередь — иначе проверять
        // нечего: письма о смене статуса просто нет.
        Bus::assertDispatched(SendOrderNotificationJob::class, static fn (SendOrderNotificationJob $job): bool =>
            $job->orderId === $order->id && $job->expectedStatus === OrderStateMachine::PAID
        );

        $tickets = app(TicketService::class)->issueTicketsForOrder($order->fresh());
        self::assertCount(1, $tickets, 'оплаченный заказ обязан получить билет');

        $ticket = Ticket::query()->where('order_id', $order->id)->firstOrFail();

        // Выполняем джобу так, как это сделал бы воркер очереди.
        (new SendOrderNotificationJob($order->id, OrderStateMachine::PAID))->handle(
            app(TransactionalMailService::class),
            app(OrderNotificationData::class),
        );

        Mail::assertSent(TemplateMail::class, static function (TemplateMail $mail) use ($ticket): bool {
            return str_contains($mail->htmlBody, (string) $ticket->ticket_number)
                && str_contains($mail->htmlBody, (string) $ticket->qr_payload)
                // Палитра назначенного макета, а не значение по умолчанию.
                && str_contains($mail->htmlBody, '#e11d48')
                && str_contains($mail->htmlBody, 'data-template="Сквозной макет"')
                && str_contains($mail->htmlBody, 'Открыть билет')
                // Текстовая версия — тоже с билетом.
                && str_contains($mail->textBody, (string) $ticket->ticket_number);
        });
    }
}
