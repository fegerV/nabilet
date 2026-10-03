<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Services;

use Nabilet\Modules\Inventory\Models\SeatHold;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * HoldSweeper — releases expired seat holds back to inventory.
 * 
 * CRITICAL PRODUCTION COMPONENT:
 * This job MUST run every minute in production to prevent:
 * - Deadlocks from holds expiring during payment
 * - Inventory starvation (all seats held but not purchased)
 * - Race conditions between hold expiry and payment webhook
 * 
 * Requirements (ТЗ §24):
 * - Run every 1-2 minutes via cron
 * - Release holds where expires_at < now() AND converted_at IS NULL
 * - Atomically increment available_quantity
 * - Log all releases for audit trail
 * - Handle concurrent sweeper instances safely
 */
class HoldSweeper
{
    /**
     * Execute the sweep operation.
     * 
     * @return array{released: int, orders_expired: int, errors: array}
     */
    public function sweep(): array
    {
        // A6/A13 (план a): сначала забираем «вечные» неоплаченные заказы. Без
        // этого шага места, удержанные checkout'ом, возвращались в продажу только
        // через 5-минутный grace холда — и зависали навсегда, если заказ так и не
        // был оплачен (дефект «места сгорают при неоплате»).
        $ordersExpired = $this->expireStaleOrders();

        $now = CarbonImmutable::now();
        $releasedCount = 0;
        $errors = [];

        try {
            // Find all expired holds that haven't been converted or released
            // Use FOR UPDATE to prevent concurrent sweeper conflicts
            $expiredHolds = DB::table('seat_holds')
                ->where('expires_at', '<', $now->toDateTimeString())
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->lockForUpdate()
                ->get(['id', 'inventory_item_id', 'quantity', 'cart_id']);

            foreach ($expiredHolds as $hold) {
                try {
                    DB::transaction(function () use ($hold, $now, &$releasedCount) {
                        // Re-check hold status inside transaction (may have been converted)
                        $freshHold = DB::table('seat_holds')
                            ->where('id', $hold->id)
                            ->lockForUpdate()
                            ->first();

                        if (!$freshHold) {
                            // Hold was deleted concurrently
                            return;
                        }

                        if ($freshHold->converted_at !== null || $freshHold->released_at !== null) {
                            // Already processed by another transaction
                            return;
                        }

                        // Verify cart still exists and is active
                        $cartStillActive = DB::table('carts')
                            ->where('id', $freshHold->cart_id)
                            ->where('status', 'active')
                            ->exists();

                        // Release the hold: mark as released and restore inventory
                        DB::table('seat_holds')
                            ->where('id', $freshHold->id)
                            ->update([
                                'released_at' => $now->toDateTimeString(),
                            ]);

                        // Atomically restore inventory quantity
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
     * A6/A13 (план a): заказы в pending/awaiting_payment/payment_failed, чья
     * корзина-холд истекла (carts.expires_at + 5-минутный grace на «оплату в
     * последнюю секунду»), переводятся в expired, а их места возвращаются в
     * продажу. Идемпотентно: повторный проход не находит уже expired-заказы;
     * возврат инвентаря защищён released_at у seat_holds (двойного возврата нет).
     *
     * Заказы без корзины (созданные напрямую через admin API) не трогаем — у них
     * нет холда, который можно просрочить.
     *
     * @return int количество переведённых в expired заказов
     */
    public function expireStaleOrders(): int
    {
        // Grace = TTL холда (expires_at корзины) + 5 минут, ровно как в
        // isHoldConvertible(): платёж, пришедший в последнюю секунду grace,
        // всё ещё конвертирует заказ в paid и этот sweep его не заберёт.
        $deadline = CarbonImmutable::now()->subMinutes(5)->toDateTimeString();

        $staleOrderIds = DB::table('orders')
            ->join('carts', 'carts.id', '=', 'orders.cart_id')
            ->whereIn('orders.status', ['pending', 'awaiting_payment', 'payment_failed'])
            ->where('carts.expires_at', '<', $deadline)
            ->whereNull('orders.paid_at')
            ->limit(100)
            ->pluck('orders.id');

        $expired = 0;

        foreach ($staleOrderIds as $orderId) {
            try {
                DB::transaction(function () use ($orderId, &$expired): void {
                    $order = \Nabilet\Modules\Orders\Models\Order::query()
                        ->lockForUpdate()
                        ->find($orderId);

                    if ($order === null) {
                        return;
                    }

                    // Финальная проверка состояния внутри транзакции: параллельный
                    // succeeded-вебхук уже мог увести заказ в paid.
                    if (!in_array($order->status, ['pending', 'awaiting_payment', 'payment_failed'], true)
                        || $order->paid_at !== null) {
                        return;
                    }

                    $cart = \Nabilet\Modules\Cart\Models\Cart::find($order->cart_id);

                    if ($cart !== null && $cart->status === 'active') {
                        $cart->update(['status' => 'abandoned']);
                    }

                    $order->update(['status' => 'expired']);

                    if ($cart !== null) {
                        app(\Nabilet\Modules\Cart\Services\CartService::class)
                            ->releaseCartInventory($cart);
                    } else {
                        // Корзина удалена (легаси-данные) — освобождаем холды и
                        // инвентарь напрямую по строкам seat_holds.
                        $orphanHolds = DB::table('seat_holds')
                            ->where('cart_id', $order->cart_id)
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

                    $expired++;

                    Log::info('HoldSweeper: Order expired, inventory released', [
                        'order_id' => $order->id,
                        'cart_id' => $order->cart_id,
                    ]);
                });
            } catch (\Throwable $e) {
                Log::error('HoldSweeper: order expiry failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $expired;
    }

    /**
     * Check if a specific hold is still convertible.
     * Used during payment webhook processing to prevent race conditions.
     * 
     * @param int $holdId
     * @return bool True if hold is still valid for conversion
     */
    public function isHoldConvertible(int $holdId): bool
    {
        $hold = DB::table('seat_holds')
            ->where('id', $holdId)
            ->first();

        if (!$hold) {
            return false;
        }

        // Already converted or released
        if ($hold->converted_at !== null || $hold->released_at !== null) {
            return false;
        }

        // Check if expired
        $now = CarbonImmutable::now();
        $expiresAt = CarbonImmutable::parse($hold->expires_at);

        // Allow grace period of 5 minutes after expiry for payment completion
        $gracePeriod = $expiresAt->addMinutes(5);

        return $now->lt($gracePeriod);
    }

    /**
     * Mark a hold as converted (successful payment).
     * Called after payment webhook confirms success.
     * 
     * @param int $holdId
     * @return bool
     */
    public function markAsConverted(int $holdId): bool
    {
        $now = CarbonImmutable::now();

        $affected = DB::table('seat_holds')
            ->where('id', $holdId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->update([
                'converted_at' => $now->toDateTimeString(),
            ]);

        return $affected > 0;
    }

    /**
     * Get statistics on holds for monitoring dashboard.
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
