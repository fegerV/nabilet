<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Observers;

use Illuminate\Support\Str;
use Nabilet\Modules\Notifications\Jobs\SendOrderNotificationJob;
use Nabilet\Modules\Notifications\Support\OrderNotificationCodes;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Support\OrderEventName;
use Nabilet\Modules\Webhooks\Jobs\DispatchOrderWebhookJob;

/**
 * Реакция на смену статуса заказа: письмо покупателю и исходящие вебхуки.
 *
 * Обсервер — единственная точка, где ловятся ВСЕ переходы статусов. Их меняют
 * из четырёх мест (OrderService, RefundService, PaymentService, StaleOrderExpirer),
 * и вызывать рассылку из каждого значит гарантированно забыть один путь.
 * HookRegistry не подходит: это реестр фильтров/пайпов, а не жизненного цикла.
 *
 * `updated()`, а не `saved()`: статусы пишутся через `$order->update([...])`,
 * то есть на уже существующей модели.
 *
 * Обе задачи ставятся `afterCommit()`: они не уйдут при откате заказа и не
 * выполняют сетевую работу внутри транзакции оплаты. Fan-out вебхуков вынесен
 * во вторую job, чтобы даже сбой записи webhook_deliveries не мог отменить
 * купленный билет.
 */
class OrderObserver
{
    public function updated(Order $order): void
    {
        // Тот же статус мог быть записан повторно (идемпотентный
        // markAwaitingPayment) — рассылку это не повод дублировать.
        if (! $order->wasChanged('status')) {
            return;
        }

        $status = (string) $order->status;
        $eventName = OrderEventName::forStatus($status);

        if ($eventName === null) {
            return;
        }

        if (OrderNotificationCodes::forStatus($status) !== null) {
            SendOrderNotificationJob::dispatch($order->id, $status)
                ->onConnection('database')
                ->afterCommit();
        }

        DispatchOrderWebhookJob::dispatch(
            eventName: $eventName,
            payload: $this->orderPayload($order),
            organizationId: (int) $order->organization_id,
            eventId: (string) Str::uuid(),
        )
            ->onConnection('database')
            ->afterCommit();
    }

    /**
     * Полезная нагрузка вебхука — только факты уровня заказа, без PII.
     *
     * Партнёру достаточно идентификатора заказа, статуса и суммы; детали он
     * получает отдельным авторизованным API-запросом. Это уменьшает утечку
     * персональных данных и держит вебхук-контракт стабильным. Расширять его
     * можно только добавлением полей — удаление ломает подписчиков.
     *
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_amount' => (int) $order->total_amount,
            'currency' => $order->currency,
            'event_id' => $order->event_id,
            'session_id' => $order->session_id,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ];
    }
}
