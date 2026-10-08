<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Notifications\Services\OrderNotificationData;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Nabilet\Modules\Notifications\Support\OrderNotificationCodes;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Письмо по смене статуса заказа.
 *
 * В очередь, а не синхронно: отправка — это сеть, и её latency не должна
 * попадать в транзакцию оплаты. Обсервер ставит джобу `afterCommit()`, поэтому
 * она уходит только после коммита — письмо об оплате не может опередить саму
 * оплату и не уйдёт, если транзакция откатилась.
 *
 * Статус передаётся в джобу, а не читается из заказа в handle(): между
 * постановкой в очередь и выполнением заказ мог сменить статус ещё раз
 * (оплата → возврат), и тогда письмо ушло бы не о том событии. Текущий статус
 * сверяется с ожидаемым; при расхождении письмо молча не отправляется.
 */
class SendOrderNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Ошибка SMTP повторяется независимо от транзакции оплаты заказа. */
    public int $tries = 3;

    /** Несколько минут на восстановление SMTP, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    /**
     * Напоминание за сутки до мероприятия — код, у которого нет парного статуса.
     *
     * `expectedStatus` здесь равен самому коду, а не статусу заказа: у напоминания
     * статуса нет, и сверять его с `$order->status` бессмысленно. Чтобы не
     * заводить второй конструктор и не плодить похожие джобы, признак отделён
     * явно: `reminder = true` переключает и выбор кода, и набор проверок.
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $expectedStatus,
        public readonly bool $reminder = false,
    ) {}

    /** Напоминание за сутки до мероприятия: заказ должен быть оплачен. */
    public static function forReminder(int $orderId): self
    {
        return new self(
            orderId: $orderId,
            expectedStatus: OrderNotificationCodes::REMINDER,
            reminder: true,
        );
    }

    public function handle(TransactionalMailService $mail, OrderNotificationData $data): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        if ($this->reminder) {
            // Заказ мог отменить или вернуть его между постановкой в очередь и
            // отправкой. Напоминание по такому заказу звало бы человека на
            // мероприятие без билета, поэтому сверяем статус с PAID, а не с
            // `expectedStatus` (который равен коду шаблона).
            if ($order->status !== OrderStateMachine::PAID) {
                return;
            }

            $mail->send(
                code: $this->expectedStatus,
                recipient: (string) $order->customer_email,
                variables: $data->build($order),
                userId: $order->user_id,
            );

            return;
        }

        $code = OrderNotificationCodes::forStatus($this->expectedStatus);

        if ($code === null) {
            return;
        }

        if ($order->status !== $this->expectedStatus) {
            return;
        }

        $mail->send(
            code: $code,
            recipient: (string) $order->customer_email,
            variables: $data->build($order),
            userId: $order->user_id,
        );
    }
}
