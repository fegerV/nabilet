<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nabilet\Core\Support\HoldGrace;
use Nabilet\Modules\Orders\Services\StaleOrderExpirer;

/**
 * HoldSweeper — возвращает просроченные брони мест в продажу.
 *
 * КРИТИЧЕСКИЙ ПРОИЗВОДСТВЕННЫЙ КОМПОНЕНТ.
 * В проде этот проход ОБЯЗАН запускаться каждую минуту (см.
 * `ClearExpiredHoldsCommand` и `routes/console.php`). Без него:
 *  - инвентарь голодает: все места удержаны, но не куплены;
 *  - истечение холда во время оплаты блокирует платежи;
 *  - накапливаются гонки между снятием холда и вебхуком оплаты.
 *
 * Требования (ТЗ §24):
 *  - запуск раз в 1–2 минуты по cron;
 *  - снимать холды, где `expires_at < now() − grace` и `converted_at IS NULL`;
 *  - атомарно увеличивать `available_quantity`;
 *  - писать в лог каждое снятие для аудита;
 *  - корректно переживать параллельные запуски.
 *
 * ОБЯЗАННОСТИ РАЗДЕЛЕНЫ (P2). Раньше класс совмещал три несвязанные роли, и
 * это было источником дефектов, а не только неудобством чтения:
 *  - снятие холдов — здесь;
 *  - закрытие «вечных» неоплаченных заказов — `Orders\Services\StaleOrderExpirer`
 *    (это про заказы, а не про холды: у них своя причина просрочки);
 *  - проверка/перевод ОДНОГО холда в момент оплаты —
 *    `SeatHoldLifecycle` (точечная операция внутри вебхука, а не пакетная).
 *
 * Общее у всех трёх — только grace-окно, и оно вынесено в
 * `Nabilet\Core\Support\HoldGrace`, чтобы sweeper и вебхук не разошлись в
 * оценке «жив ли ещё холд».
 */
class HoldSweeper
{
    public function __construct(private readonly StaleOrderExpirer $staleOrders)
    {
    }

    /**
     * Выполнить проход: закрыть просроченные заказы, затем снять просроченные
     * холды.
     *
     * Порядок важен и не переставляется. Сначала заказы: заказ, чья корзина
     * истекла, освобождает свои места сам (через
     * `CartItemService::releaseCartInventory()`), и делает это идемпотентно. Если
     * сначала пройти по холдам, то места вернутся под флагом `released_at`, а
     * заказ останется `pending` — отчётность разойдётся с фактическим
     * инвентарём.
     *
     * @return array{released: int, orders_expired: int, errors: array}
     */
    public function sweep(): array
    {
        // A6/A13 (план a): сначала забираем «вечные» неоплаченные заказы. Без
        // этого шага места, удержанные checkout'ом, возвращались в продажу только
        // через grace-окно холда — и зависали навсегда, если заказ так и не
        // был оплачен (дефект «места сгорают при неоплате»).
        $ordersExpired = $this->staleOrders->expireStaleOrders();

        // Момент фиксируется один раз на весь проход: иначе два вызова now()
        // внутри цикла дали бы разные отметки и окно «поплыло» бы на длинном
        // проходе.
        $now = CarbonImmutable::now();
        $releasedCount = 0;
        $errors = [];

        try {
            // A13: освобождаем холд только после истечения grace-окна
            // (expires_at + grace) — ровно как в `SeatHoldLifecycle::isHoldConvertible()`.
            // Раньше sweep снимал холд сразу по expires_at, «побеждая» grace-окно:
            // вебхук, пришедший в последнюю секунду grace, видел released_at и
            // отклонял платёж, хотя по логике он ещё валиден (race-condition).
            //
            // `lockForUpdate()` — чтобы два параллельных sweeper'а не выбрали одни
            // и те же строки.
            $expiredHolds = DB::table('seat_holds')
                ->where('expires_at', '<', HoldGrace::cutoff($now)->toDateTimeString())
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->lockForUpdate()
                ->get(['id', 'inventory_item_id', 'quantity', 'cart_id']);

            foreach ($expiredHolds as $hold) {
                try {
                    DB::transaction(function () use ($hold, $now, &$releasedCount) {
                        // Перечитываем холд внутри транзакции: пока шёл отбор, его
                        // мог сконвертировать вебхук оплаты.
                        $freshHold = DB::table('seat_holds')
                            ->where('id', $hold->id)
                            ->lockForUpdate()
                            ->first();

                        if ($freshHold === null) {
                            // Холд удалён параллельной транзакцией.
                            return;
                        }

                        if ($freshHold->converted_at !== null || $freshHold->released_at !== null) {
                            // Уже обработан другой транзакцией — идемпотентность.
                            return;
                        }

                        // Корзина ещё жива? Это только для аудита: сам факт снятия
                        // холда от неё не зависит, но в логе полезно видеть, был ли
                        // это «заброшенный» холд или гонка с активной корзиной.
                        $cartStillActive = DB::table('carts')
                            ->where('id', $freshHold->cart_id)
                            ->where('status', 'active')
                            ->exists();

                        DB::table('seat_holds')
                            ->where('id', $freshHold->id)
                            ->update([
                                'released_at' => $now->toDateTimeString(),
                            ]);

                        // Атомарно возвращаем количество в инвентарь.
                        $affected = DB::table('inventory_items')
                            ->where('id', $freshHold->inventory_item_id)
                            ->increment('available_quantity', (int) $freshHold->quantity);

                        if ($affected === 0) {
                            Log::warning('HoldSweeper: Failed to restore inventory', [
                                'hold_id' => $freshHold->id,
                                'inventory_item_id' => $freshHold->inventory_item_id,
                                'quantity' => $freshHold->quantity,
                            ]);
                        }

                        // A13: помечаем корзину abandoned — иначе истёкшая через
                        // sweeper корзина оставалась 'active' с просроченным
                        // expires_at, хотя места уже возвращены (checkout-путь ставил
                        // abandoned, а sweep — нет, создавая расхождение в отчётности).
                        DB::table('carts')
                            ->where('id', $freshHold->cart_id)
                            ->where('status', 'active')
                            ->update(['status' => 'abandoned']);

                        $releasedCount++;

                        Log::info('HoldSweeper: Released expired hold', [
                            'hold_id' => $freshHold->id,
                            'cart_id' => $freshHold->cart_id,
                            'inventory_item_id' => $freshHold->inventory_item_id,
                            'quantity' => $freshHold->quantity,
                            'expired_at' => $freshHold->expires_at,
                            'released_at' => $now->toIso8601String(),
                            'cart_was_active' => $cartStillActive,
                        ]);
                    });
                } catch (\Throwable $e) {
                    // Один сбойный холд не должен останавливать проход: остальные
                    // места обязаны вернуться в продажу.
                    $errors[] = [
                        'hold_id' => $hold->id,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('HoldSweeper: Error releasing hold', [
                        'hold_id' => $hold->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'released' => $releasedCount,
                'orders_expired' => $ordersExpired,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            Log::critical('HoldSweeper: Critical failure', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'released' => $releasedCount,
                'orders_expired' => $ordersExpired,
                'errors' => [['error' => 'Critical failure: ' . $e->getMessage()]],
            ];
        }
    }

    /**
     * Статистика по холдам для мониторинга и `--stats`.
     *
     * Все три числа считаются по «незакрытым» холдам (`converted_at` и
     * `released_at` пусты) — иначе закрытые брони раздували бы «активные».
     *
     * @return array{total_active: int, total_expired: int, expiring_soon: int}
     */
    public function getStats(): array
    {
        $now = CarbonImmutable::now();
        $expiringSoon = $now->copy()->addMinutes(10);

        return [
            'total_active' => DB::table('seat_holds')
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->count(),
            'total_expired' => DB::table('seat_holds')
                ->where('expires_at', '<', $now->toDateTimeString())
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->count(),
            'expiring_soon' => DB::table('seat_holds')
                ->where('expires_at', '>', $now->toDateTimeString())
                ->where('expires_at', '<', $expiringSoon->toDateTimeString())
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->count(),
        ];
    }
}
