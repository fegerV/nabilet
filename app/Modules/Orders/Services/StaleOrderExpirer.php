<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nabilet\Core\Support\HoldGrace;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Services\CartItemService;
use Nabilet\Modules\Orders\Models\Order;

/**
 * StaleOrderExpirer — забирает «вечные» неоплаченные заказы и возвращает их
 * места в продажу.
 *
 * Отделено от `Inventory\Services\HoldSweeper` (P2). Sweeper снимает ХОЛДЫ;
 * этот класс закрывает ЗАКАЗЫ. Это разные сущности и разные причины
 * просрочки:
 *
 *  - холд истекает по `seat_holds.expires_at` — его продлевает только оплата;
 *  - заказ висит в `pending`/`awaiting_payment`/`payment_failed`, даже когда
 *    его корзина-холд давно истекла. Такой заказ — «зомби»: места за ним уже
 *    никто не держит, но статус `pending` не даёт отчётности закрыться, а
 *    повторная попытка оплаты по нему пройдёт проверку хуже, чем должна.
 *
 * Без этого шага места, удержанные checkout'ом, возвращались в продажу только
 * через grace-окно холда — и зависали навсегда, если заказ так и не был
 * оплачен (дефект «места сгорают при неоплате»).
 *
 * Идемпотентность. Повторный проход не находит уже `expired`-заказы (они
 * выпадают из `whereIn`), а возврат инвентаря защищён `released_at` у
 * `seat_holds` — двойного возврата нет даже при параллельных запусках.
 *
 * Заказы без корзины (созданные напрямую через admin API) не трогаем — у них
 * нет холда, который можно просрочить.
 */
final class StaleOrderExpirer
{
    /**
     * Статусы, в которых заказ считается «ещё не оплачен, но и не закрыт».
     */
    private const OPEN_STATUSES = ['pending', 'awaiting_payment', 'payment_failed'];

    /**
     * Потолок на число заказов за один проход. Sweeper идёт по Cron раз в
     * минуту; ограничение не даёт одному запуску заблокировать таблицу
     * `orders`, если после сбоя накопились тысячи просроченных заказов.
     * Остаток заберёт следующий запуск.
     */
    private const BATCH_LIMIT = 100;

    public function __construct(private readonly CartItemService $carts)
    {
    }

    /**
     * Перевести просроченные неоплаченные заказы в `expired` и вернуть их места
     * в продажу.
     *
     * Порог просрочки — `carts.expires_at` + grace-окно, то есть ровно то же
     * окно, по которому `SeatHoldLifecycle::isHoldConvertible()` решает, что
     * платёж ещё валиден. Без выравнивания платёж, пришедший в последнюю
     * секунду grace, перевёл бы заказ в `paid` уже после того, как этот метод
     * объявил его `expired` и распродал места.
     *
     * @return int количество заказов, переведённых в `expired`
     */
    public function expireStaleOrders(): int
    {
        $deadline = HoldGrace::cutoff()->toDateTimeString();

        $staleOrderIds = DB::table('orders')
            ->join('carts', 'carts.id', '=', 'orders.cart_id')
            ->whereIn('orders.status', self::OPEN_STATUSES)
            ->where('carts.expires_at', '<', $deadline)
            ->whereNull('orders.paid_at')
            ->limit(self::BATCH_LIMIT)
            ->pluck('orders.id');

        $expired = 0;

        foreach ($staleOrderIds as $orderId) {
            try {
                DB::transaction(function () use ($orderId, &$expired): void {
                    $order = Order::query()
                        ->lockForUpdate()
                        ->find($orderId);

                    if ($order === null) {
                        return;
                    }

                    // Финальная проверка состояния внутри транзакции:
                    // параллельный succeeded-вебхук уже мог увести заказ в paid.
                    if (! in_array($order->status, self::OPEN_STATUSES, true)
                        || $order->paid_at !== null) {
                        return;
                    }

                    $cart = Cart::find($order->cart_id);

                    if ($cart !== null && $cart->status === 'active') {
                        $cart->update(['status' => 'abandoned']);
                    }

                    $order->update(['status' => 'expired']);

                    if ($cart !== null) {
                        $this->carts->releaseCartInventory($cart);
                    } else {
                        $this->releaseOrphanHolds((int) $order->cart_id);
                    }

                    $expired++;

                    Log::info('StaleOrderExpirer: Order expired, inventory released', [
                        'order_id' => $order->id,
                        'cart_id' => $order->cart_id,
                    ]);
                });
            } catch (\Throwable $e) {
                // Один сбойный заказ не должен останавливать проход по остальным.
                Log::error('StaleOrderExpirer: order expiry failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $expired;
    }

    /**
     * Корзина удалена (легаси-данные) — освобождаем холды и инвентарь напрямую
     * по строкам `seat_holds`, потому что `CartItemService::releaseCartInventory()`
     * работает от модели корзины, которой уже нет.
     */
    private function releaseOrphanHolds(int $cartId): void
    {
        $orphanHolds = DB::table('seat_holds')
            ->where('cart_id', $cartId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($orphanHolds as $hold) {
            DB::table('inventory_items')
                ->where('id', $hold->inventory_item_id)
                ->where('status', '!=', 'sold')
                ->increment('available_quantity', (int) $hold->quantity);

            DB::table('seat_holds')
                ->where('id', $hold->id)
                ->update(['released_at' => CarbonImmutable::now()->toDateTimeString()]);
        }
    }
}
