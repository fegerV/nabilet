<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\SeatHold;
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
     * @return array{released: int, errors: array}
     */
    public function sweep(): array
    {
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
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            Log::critical('HoldSweeper: Critical failure', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'released' => $releasedCount,
                'errors' => [['error' => 'Critical failure: ' . $e->getMessage()]],
            ];
        }
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
