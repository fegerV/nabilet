<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Observers;

use Nabilet\Modules\Matrix\Domain\MatrixNotice;
use Nabilet\Modules\Matrix\Jobs\SendMatrixMessageJob;
use Nabilet\Modules\Matrix\Services\MatrixSettings;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Административное уведомление об оплате заказа — в комнату Matrix.
 *
 * КОМУ ЭТО АДРЕСОВАНО
 *   Только сотрудникам. Сообщение уходит в настроенную комнату, которую читают
 *   администраторы; покупателю Matrix-канал не отправляет ничего (привязки
 *   «покупатель ↔ Matrix ID» в схеме нет и не заводится). Это внутренняя лента
 *   продаж, а не канал обслуживания клиента.
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ВЫЗОВ ИЗ СЦЕНАРИЯ ОПЛАТЫ
 *   Заказ становится `paid` из нескольких мест (`OrderService::markPaid()`,
 *   подтверждение платежа, админские сценарии) — тот же аргумент, что у
 *   `OrderBonusObserver` и соседей по цепочке наблюдателей `Order`. Уведомление
 *   привязано к СОБЫТИЮ «заказ оплачен», а не к пути, которым оно наступило,
 *   иначе один путь уведомлял бы, а остальные — нет.
 *
 * ПОЧЕМУ `wasChanged('status')`
 *   Идемпотентный `markPaid()` может записать тот же статус повторно. Без этой
 *   проверки администратор получил бы второе сообщение о той же оплате.
 */
final class OrderMatrixObserver
{
    public function __construct(private readonly MatrixSettings $settings) {}

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        if ($order->status !== OrderStateMachine::PAID) {
            return;
        }

        // Канал выключен или уведомления о заказах отключены — молчим. Проверка
        // здесь, а не в джобе: не нужно ставить задачу в очередь, чтобы она
        // сразу же вышла.
        if (! $this->settings->isEnabled() || ! $this->settings->notifyOrders()) {
            return;
        }

        // Если канал включён, но не заполнен, отправка не имеет смысла — и
        // повторять её бессмысленно. Проверяем здесь, чтобы не копить в очереди
        // заведомо падающие задачи.
        if (! $this->settings->isConfigured()) {
            return;
        }

        $body = MatrixNotice::orderPaid([
            'order_number' => (string) $order->order_number,
            'total_minor' => (int) $order->total_amount,
            'currency' => (string) ($order->currency ?: 'RUB'),
            'event_title' => $order->event?->title,
            'customer' => (string) ($order->customer_name ?: $order->customer_email),
        ]);

        SendMatrixMessageJob::dispatch($body)
            ->onConnection('database')
            ->afterCommit();
    }
}
