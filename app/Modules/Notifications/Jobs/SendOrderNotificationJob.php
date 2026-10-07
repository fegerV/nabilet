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

    public function __construct(
        public readonly int $orderId,
        public readonly string $expectedStatus,
    ) {}

    public function handle(TransactionalMailService $mail, OrderNotificationData $data): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
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
