<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory and seat holds — THE MOST IMPORTANT TABLES IN THE SYSTEM
 * (ТЗ §22, §23, §24, §84, §90).
 *
 * ── Why a separate inventory table at all ────────────────────────────────────
 *
 * The spec's central architectural insight (ТЗ §90) is that the sellable entity is
 * not a seat in a hall schema — it is an INVENTORY ITEM belonging to one session.
 *
 *     Seat A-12 in HallSchemaVersion 7   ← physical reality, shared by many sessions
 *     InventoryItem #99120               ← what is actually sold, priced, held, refunded
 *
 * This separation is what makes the following possible without special cases:
 * different prices per session, VIP tiers, dynamic pricing, blocking a seat for one
 * performance only, and refunds that release exactly one seat.
 *
 * ── The anti-double-sell design ──────────────────────────────────────────────
 *
 * Three layers, because one is not enough:
 *
 *   1. UNIQUE (event_session_id, inventory_key)
 *      Guarantees structurally that one session cannot contain the same seat twice.
 *      `inventory_key` is a normalised discriminator ("seat:123", "standing:45:7")
 *      so seats, standing slots and table places all share ONE uniqueness rule.
 *
 *   2. TRANSACTION + SELECT ... FOR UPDATE on the inventory row
 *      Serialises concurrent buyers. Two requests for seat A-12 are queued; the
 *      first commits, the second re-reads `status` and sees `held`/`sold`.
 *
 *   3. UNIQUE (inventory_item_id, is_active) on seat_holds
 *      A database-level backstop: even if application logic is wrong, only ONE
 *      active hold per inventory item can exist. `is_active` is NULL for closed
 *      holds, and MySQL permits unlimited NULLs in a unique index — this emulates a
 *      partial index (which MySQL lacks) without a generated column.
 *
 * Layer 3 is the one that survives a future refactor by someone who has not read
 * this comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Inventory items — the sellable unit (ТЗ §90) ─────────────────────
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('event_session_id')->constrained('event_sessions')->restrictOnDelete();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->restrictOnDelete()
                ->comment('Denormalised from the session for a single-join seat map query');

            $table->enum('kind', ['seat', 'standing', 'table_place'])->default('seat');

            // Exactly one of these is set, depending on `kind`.
            $table->foreignId('seat_id')->nullable()->constrained('seats')->restrictOnDelete();
            $table->foreignId('standing_zone_id')->nullable()->constrained('standing_zones')->restrictOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->restrictOnDelete();

            // Denormalised for fast grouping in reports (sales by sector / by row)
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->restrictOnDelete();
            $table->foreignId('row_id')->nullable()->constrained('rows')->restrictOnDelete();

            /**
             * Normalised identity within a session:
             *   seat          -> "seat:{seat_id}"
             *   standing      -> "standing:{zone_id}:{slot}"
             *   table_place   -> "table:{table_id}:{place}"
             * This is the column the uniqueness constraint is built on.
             */
            $table->string('inventory_key', 96);

            // Human-facing location, snapshotted so a ticket PDF does not depend on
            // joins to the (immutable but large) schema tables.
            $table->string('sector_name', 191)->nullable();
            $table->string('sector_code', 32)->nullable();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('seat_number', 32)->nullable();
            $table->string('seat_label', 512)->nullable()
                ->comment('Rendered label, e.g. "Сектор A, ряд 12, место 18"');

            // ── Pricing lives HERE, not on the row template (ТЗ §22) ─────────
            $table->bigInteger('price_minor')->default(0)->comment('Minor units (kopecks)');
            $table->char('currency', 3)->default('RUB');
            $table->string('price_tier', 64)->nullable()->comment('e.g. early_bird, vip, standard');
            $table->bigInteger('base_price_minor')->nullable()
                ->comment('Price before promo/dynamic adjustment, kept for reporting');

            // ── Status (ТЗ §23) ──────────────────────────────────────────────
            $table->enum('status', ['available', 'held', 'sold', 'blocked', 'disabled'])
                ->default('available');

            $table->foreignId('current_hold_id')->nullable()
                ->comment('Active hold; FK added after seat_holds exists');
            $table->foreignId('order_item_id')->nullable()
                ->comment('Set on sale; FK added after order_items exists');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('blocked_until')->nullable();

            $table->string('type', 32)->default('standard')
                ->comment('Mirrors seats.type: standard | vip | wheelchair | companion | blocked | custom');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            // LAYER 1: structural guarantee of one inventory row per seat per session
            $table->unique(['event_session_id', 'inventory_key'], 'inventory_session_key_unique');

            // The seat map query: all items of a session by status
            $table->index(['event_session_id', 'status'], 'inventory_session_status_idx');
            $table->index(['event_session_id', 'sector_id']);
            $table->index(['organization_id', 'status']);
            $table->index('seat_id');
            $table->index('standing_zone_id');
        });

        // ── Seat holds — temporary reservation (ТЗ §24, §84) ─────────────────
        Schema::create('seat_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('event_session_id')->constrained('event_sessions')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();

            // Who is holding. A guest checkout (ТЗ §83) has no user yet, so the
            // cart plus a guest token identifies the holder.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cart_id')->nullable()->comment('FK added after carts exists');
            $table->string('guest_token', 64)->nullable()->index();
            $table->string('holder_fingerprint', 64)->nullable()
                ->comment('Hash of IP+UA; used to detect a hold being hijacked by another client');

            $table->enum('status', ['active', 'converted', 'expired', 'released'])->default('active');

            /**
             * LAYER 3 of the anti-double-sell design.
             * 1 while the hold is active, NULL once it is closed.
             * Combined with the unique index below this emulates a partial unique
             * index, which MySQL does not support natively.
             */
            $table->unsignedTinyInteger('is_active')->nullable()->default(1);

            $table->dateTime('expires_at');
            $table->foreignId('converted_order_id')->nullable()
                ->comment('Set when the hold becomes an order; FK added after orders exists');
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 32)->nullable()->comment('expired | user | payment_failed');

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // At most one ACTIVE hold per inventory item (NULLs are not compared).
            $table->unique(['inventory_item_id', 'is_active'], 'hold_one_active_per_item');

            // The sweeper: find expired active holds efficiently.
            $table->index(['status', 'expires_at'], 'holds_sweep_idx');
            $table->index(['event_session_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['cart_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_holds');
        Schema::dropIfExists('inventory_items');
    }
};
