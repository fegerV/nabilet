<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Modules\Loyalty\Services\ReferralService;
use Nabilet\Modules\Orders\Models\Order;

/**
 * Награда обеим сторонам за первую оплату друга (фаза 4).
 *
 * ПОЧЕМУ ДЖОБА, А НЕ ВЫЗОВ ПРЯМО ИЗ ОБСЕРВЕРА
 *   Тот же порядок приоритетов, что у кэшбэка (`AccrueOrderBonusJob`):
 *   награда — запись в две таблицы, и она не должна выполняться внутри
 *   транзакции оплаты. Сбой рефералки не имеет права отменить купленный
 *   билет, а `afterCommit()` на постановке (см. наблюдателя) не даёт джобе
 *   уйти от откатившейся транзакции.
 *
 * ПОЧЕМУ ПОВТОР БЕЗОПАСЕН
 *   Джоба повторяется (`tries`/`backoff`), и повтор не должен начислять
 *   второй раз. Защита двойная: строка приглашения уходит в `rewarded`
 *   (`ReferralService::rewardForOrder()` её просто не найдёт), а каждая
 *   выплата защищена `uq_bonus_transactions_dedupe`. Исключение отсюда не
 *   глотается — следующий проход увидит, что награда уже прошла.
 *
 * Заказ читается заново, а не передаётся сериализованным: между оплатой и
 * исполнением заказ мог бы быть возвращён, и решать должно состояние на
 * момент награды (возврат, как записано в плане, награду не отзывает —
 * но знать об этом должна именно текущая строка заказа).
 */
class RewardReferralJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Сбой записи повторяем: награда идемпотентна, лишнего не случится. */
    public int $tries = 3;

    /** Минуты на восстановление БД, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $orderId,
    ) {}

    /**
     * Поставить награду в очередь. Возвращается `PendingDispatch`, а не джоба:
     * вызывающий навешивает `onConnection()`/`afterCommit()` на ПОСТАНОВКУ —
     * см. `AccrueOrderBonusJob::forOrder()`.
     */
    public static function forOrder(Order $order): PendingDispatch
    {
        return self::dispatch(orderId: (int) $order->getKey());
    }

    public function handle(ReferralService $referrals): void
    {
        $order = Order::query()->find($this->orderId);

        // Заказ удалён (каскад унёс и его следы) — награжать не за что.
        if ($order === null) {
            return;
        }

        // Решение «платить ли» принимает сервис: там же лежат проверка
        // включённой программы, поиск приглашения и сторона-получатель.
        // Дублировать её здесь значило бы иметь два места, которые могут
        // разойтись.
        $referrals->rewardForOrder($order);
    }
}
