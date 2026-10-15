<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Orders\Models\Order;

/**
 * Возврат списанных бонусов: отмена, истечение срока оплаты или возврат заказа.
 *
 * ПОЧЕМУ ДЖОБА, А НЕ ВЫЗОВ ПРЯМО ИЗ ОБСЕРВЕРА
 *   Ровно та же причина, что у `AccrueOrderBonusJob`, и она здесь даже
 *   сильнее. Возврат инициирует `RefundService` (или админский сценарий отмены)
 *   внутри транзакции, которая двигает ДЕНЬГИ покупателя. Если бы бонусный счёт
 *   писался в той же транзакции, сбой на нём откатил бы возврат денег: клиент не
 *   получил бы назад оплату из-за того, что не сошлась лояльность. Порядок
 *   приоритетов однозначный — сначала деньги покупателя, потом бонусы.
 *
 *   Обратная сторона честно называется: если джоба не пройдёт за все `tries`,
 *   бонусы останутся списанными по несостоявшемуся заказу, и это будет видно в
 *   очереди, а не в сверке. Направление ошибки — «покупатель недополучил
 *   бонусы», а не «покупатель не получил деньги».
 *
 * ПОЧЕМУ ПОВТОР БЕЗОПАСЕН
 *   `BonusService::refundForOrder()` идемпотентен по `dedupe_key = refund:{order_id}`:
 *   возврат может быть инициирован дважды (обсервер сработал на `cancelled`,
 *   затем админский сценарий выставил `expired`), и второй раз вернуть нельзя.
 *
 * Заказ читается заново, а не передаётся сериализованным: между постановкой в
 * очередь и исполнением заказ мог измениться — в частности, у него мог
 * появиться `bonus_amount`, которого не было на момент перехода статуса.
 */
class RefundOrderBonusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Сбой записи повторяем: возврат идемпотентен, лишнего не случится. */
    public int $tries = 3;

    /** Минуты на восстановление БД, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $orderId,
    ) {}

    /** @return PendingDispatch постановка, а не готовая джоба — см. `AccrueOrderBonusJob::forOrder()`. */
    public static function forOrder(Order $order): PendingDispatch
    {
        return self::dispatch(orderId: (int) $order->getKey());
    }

    public function handle(BonusService $bonus): void
    {
        $order = Order::query()->find($this->orderId);

        // Заказ удалён — возвращать нечего и некуда: строка журнала ссылается на
        // него через FK `ON DELETE SET NULL`.
        if ($order === null) {
            return;
        }

        // Решение «возвращать ли» принимает сервис: там же проверка
        // `bonus_amount` и владельца. Дублировать её здесь значило бы иметь два
        // места, которые могут разойтись.
        $bonus->refundForOrder($order);
    }
}
