<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Notifications\Support\OrderNotificationCodes;
use Nabilet\Modules\Orders\Services\OrderReminderSweeper;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Напоминание за сутки до мероприятия.
 *
 * Проверяется главное свойство, ради которого напоминание сделано отдельным
 * свипом, а не веткой в обсервере: рассылка привязана ко ВРЕМЕНИ сеанса и
 * идемпотентна на повторе. Именно на повторе схема ломалась бы чаще всего —
 * крон идёт по расписанию, и без журнала письмо уходило бы каждый тик.
 */
class OrderReminderTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    /**
     * Оплаченный заказ, чей сеанс начинается ровно через сутки, получает
     * напоминание с кодом order.reminder.
     */
    public function test_paid_order_with_session_tomorrow_gets_a_reminder(): void
    {
        Bus::fake();

        [$order] = $this->seedPaidOrderStartingIn(hours: 24);

        $result = app(OrderReminderSweeper::class)->sweep();

        self::assertSame(1, $result['sent']);

        Bus::assertDispatched(SendOrderNotificationJob::class, function (SendOrderNotificationJob $job) use ($order): bool {
            return $job->orderId === $order->id
                && $job->reminder === true
                && $job->expectedStatus === OrderNotificationCodes::REMINDER;
        });
    }

    /**
     * Повторный проход НЕ отправляет письмо второй раз. Это ключевой тест:
     * свип запускается по крону, и без журнала напоминание уходило бы каждый
     * час, пока сеанс не наступит.
     */
    public function test_second_sweep_does_not_send_the_reminder_again(): void
    {
        Bus::fake();

        [$order] = $this->seedPaidOrderStartingIn(hours: 24);

        $first = app(OrderReminderSweeper::class)->sweep();
        $second = app(OrderReminderSweeper::class)->sweep();

        self::assertSame(1, $first['sent']);
        self::assertSame(0, $second['sent'], 'напоминание ушло повторно');

        self::assertSame(
            1,
            DB::table('order_reminders')->where('order_id', $order->id)->count(),
            'журнал напоминаний продублирован',
        );
    }

    /** Сеанс через двое суток в окно не попадает — торопиться некуда. */
    public function test_order_starting_in_two_days_is_not_reminded_yet(): void
    {
        Bus::fake();

        $this->seedPaidOrderStartingIn(hours: 48);

        self::assertSame(0, app(OrderReminderSweeper::class)->sweep()['sent']);
        $this->assertNoReminderDispatched();
    }

    /** Сеанс через час уже не «завтра» — напоминание опоздало бы. */
    public function test_order_starting_in_one_hour_is_not_reminded(): void
    {
        Bus::fake();

        $this->seedPaidOrderStartingIn(hours: 1);

        self::assertSame(0, app(OrderReminderSweeper::class)->sweep()['sent']);
        $this->assertNoReminderDispatched();
    }

    /**
     * Неоплаченный заказ не напоминаем: письмо без билета звало бы человека на
     * мероприятие, на которое у него нет входа.
     */
    public function test_unpaid_order_is_not_reminded(): void
    {
        Bus::fake();

        [$organizationId, , $sessionId, $inventoryId] = $this->seedSellableSeat(available: 1);
        $this->moveSessionTo($sessionId, hoursFromNow: 24);

        app(OrderService::class)->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        self::assertSame(0, app(OrderReminderSweeper::class)->sweep()['sent']);
        $this->assertNoReminderDispatched();
    }

    /** Отменённый сеанс: напоминать о том, чего не будет, нельзя. */
    public function test_cancelled_session_is_skipped(): void
    {
        Bus::fake();

        [, $sessionId] = $this->seedPaidOrderStartingIn(hours: 24);

        DB::table('sessions')->where('id', $sessionId)->update(['status' => 'cancelled']);

        self::assertSame(0, app(OrderReminderSweeper::class)->sweep()['sent']);
        $this->assertNoReminderDispatched();
    }

    /** Заказ, отменённый после оплаты, не должен получить напоминание. */
    public function test_refunded_order_is_not_reminded(): void
    {
        Bus::fake();

        [$order] = $this->seedPaidOrderStartingIn(hours: 24);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStateMachine::REFUNDED,
        ]);

        self::assertSame(0, app(OrderReminderSweeper::class)->sweep()['sent']);
        $this->assertNoReminderDispatched();
    }

    /** --dry-run показывает кандидатов, но ничего не пишет и не отправляет. */
    public function test_dry_run_reports_without_sending_or_writing(): void
    {
        Bus::fake();

        [$order] = $this->seedPaidOrderStartingIn(hours: 24);

        $result = app(OrderReminderSweeper::class)->sweep(dryRun: true);

        self::assertSame(1, $result['sent']);
        $this->assertNoReminderDispatched();
        self::assertSame(0, DB::table('order_reminders')->where('order_id', $order->id)->count());
    }

    /**
     * Проверяет, что СРЕДИ отправленных джоб нет напоминаний.
     *
     * Нельзя использовать `assertNotDispatched(SendOrderNotificationJob::class)`:
     * оплата заказа сама по себе порождает письмо `order.paid` через обсервер,
     * и такая проверка падала бы на законной джобе. Нас интересует только
     * `reminder = true`.
     */
    private function assertNoReminderDispatched(): void
    {
        Bus::assertNotDispatched(
            SendOrderNotificationJob::class,
            static fn (SendOrderNotificationJob $job): bool => $job->reminder === true,
        );
    }

    /**
     * Возвращает [order, sessionId] с оплаченным заказом, чей сеанс начинается
     * через $hours.
     *
     * @return array{0: \Nabilet\Modules\Orders\Models\Order, 1: int}
     */
    private function seedPaidOrderStartingIn(int $hours): array
    {
        [$organizationId, , $sessionId, $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order->fresh());
        $order = $service->markPaid($order->fresh());

        $this->moveSessionTo($sessionId, hoursFromNow: $hours);

        return [$order, $sessionId];
    }

    /**
     * Сдвигает сеанс в нужную точку относительно «сейчас».
     *
     * Фикстура всегда создаёт сеанс через неделю, поэтому дату двигаем точечно
     * здесь: иначе окно «за сутки» не воспроизвести.
     */
    private function moveSessionTo(int $sessionId, int $hoursFromNow): void
    {
        DB::table('sessions')->where('id', $sessionId)->update([
            'starts_at' => now()->addHours($hoursFromNow)->toDateTimeString(),
        ]);
    }
}
