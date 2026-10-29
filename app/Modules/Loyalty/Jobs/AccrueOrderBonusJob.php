<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Orders\Models\Order;

/**
 * Начисление кэшбэка за оплаченный заказ.
 *
 * ПОЧЕМУ ДЖОБА, А НЕ ВЫЗОВ ПРЯМО ИЗ ОБСЕРВЕРА
 *   Начисление — это запись в две таблицы, и она не должна происходить внутри
 *   транзакции оплаты: сбой бонусного счёта не имеет права отменить купленный
 *   билет. Порядок приоритетов здесь однозначный — сначала деньги покупателя,
 *   потом лояльность. `afterCommit()` на постановке (см. обсервер) гарантирует,
 *   что джоба не уйдёт от откатившейся транзакции, а сама джоба выполняется уже
 *   вне неё.
 *
 * ПОЧЕМУ ПОВТОР БЕЗОПАСЕН
 *   Джоба повторяется (`tries`/`backoff`), и повтор не должен начислять второй
 *   раз. Разрешение выдаёт уникальный ключ `uq_bonus_transactions_dedupe` —
 *   см. `BonusService::accrueForOrder()`. Поэтому исключение отсюда не
 *   глотается: следующий проход просто увидит, что начисление уже прошло.
 *
 * Заказ читается заново, а не передаётся сериализованным: между постановкой в
 * очередь и исполнением заказ мог измениться, и решать должно состояние на
 * момент начисления, а не снимок момента оплаты.
 */
class AccrueOrderBonusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Сбой записи повторяем: начисление идемпотентно, лишнего не случится. */
    public int $tries = 3;

    /** Минуты на восстановление БД, после — ошибка видна в очереди. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $orderId,
    ) {}

    /**
     * Поставить начисление в очередь.
     *
     * Возвращается `PendingDispatch`, а не джоба: вызывающий навешивает
     * `onConnection()`/`afterCommit()` на ПОСТАНОВКУ. Вернуть отсюда
     * `new self(...)` значило бы получить джобу, которую никто не поставил в
     * очередь — вызовы у самого объекта выглядят работающими, но до начисления
     * дело не доходит.
     */
    public static function forOrder(Order $order): PendingDispatch
    {
        return self::dispatch(orderId: (int) $order->getKey());
    }

    public function handle(BonusService $bonus): void
    {
        app(OrganizationContext::class)->withoutScope(function () use ($bonus): void {
            $order = Order::query()->find($this->orderId);

            // Заказ удалён (каскад унёс и его следы) — начислять не за что.
            if ($order === null) {
                return;
            }

            // Решение «начислять ли» принимает сервис: там же лежит проверка статуса
            // и привязки к покупателю. Дублировать её здесь значило бы иметь два
            // места, которые могут разойтись.
            $bonus->accrueForOrder($order);
        });
    }
}
