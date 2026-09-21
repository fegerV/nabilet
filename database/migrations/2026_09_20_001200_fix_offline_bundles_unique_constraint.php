<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Fix offline_bundles unique constraint for multi-device sync
 * 
 * Problem: The original uq_offline_bundles_hash constraint on bundle_hash alone
 * prevents multiple devices from downloading bundles for the same event/session.
 * 
 * Solution: Change unique constraint from (bundle_hash) to (bundle_hash, checkin_device_id)
 * This allows the same bundle content to be synced to multiple devices while still
 * preventing duplicate bundles per device.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the old unique constraint on bundle_hash alone
        DB::statement('ALTER TABLE offline_bundles DROP CONSTRAINT IF EXISTS uq_offline_bundles_hash;');
        
        // Add new composite unique constraint: (bundle_hash, checkin_device_id)
        // This ensures:
        // 1. Same bundle can be downloaded by multiple devices (multi-device sync)
        // 2. Each device can only have one instance of a given bundle hash
        // 3. Prevents accidental duplicate downloads on the same device
        Schema::table('offline_bundles', function (Blueprint $table) {
            $table->unique(['bundle_hash', 'checkin_device_id'], 'uq_offline_bundles_hash_device');
        });
        
        // Also add index for efficient lookups by device + hash
        Schema::table('offline_bundles', function (Blueprint $table) {
            $table->index(['checkin_device_id', 'bundle_hash'], 'idx_offline_bundles_device_hash');
        });
    }

    public function down(): void
    {
        // Remove the new composite index
        Schema::table('offline_bundles', function (Blueprint $table) {
            $table->dropIndex('idx_offline_bundles_device_hash');
        });
        
        // Drop the composite unique constraint
        DB::statement('ALTER TABLE offline_bundles DROP CONSTRAINT IF EXISTS uq_offline_bundles_hash_device;');
        
        // Restore the original unique constraint on bundle_hash alone
        Schema::table('offline_bundles', function (Blueprint $table) {
            $table->unique('bundle_hash', 'uq_offline_bundles_hash');
        });
    }
};
