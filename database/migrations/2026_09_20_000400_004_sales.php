<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: sessions, inventory_items, carts, cart_items, seat_holds, orders, order_items, promo_codes, promo_code_redemptions
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - sessions
 * - inventory_items
 * - carts
 * - cart_items
 * - seat_holds
 * - orders
 * - order_items
 * - promo_codes
 * - promo_code_redemptions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("sessions", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('venue_id');
            $table->unsignedBigInteger('hall_id');
            $table->unsignedBigInteger('schema_version_id');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->dateTime('sales_start_at', 6)->nullable();
            $table->dateTime('sales_end_at', 6)->nullable();
            $table->string('timezone', 64);
            $table->string('status', 32);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['event_id', 'starts_at'], "idx_sessions_event_start");
            $table->index(['status', 'starts_at'], "idx_sessions_status_start");
            $table->index(['venue_id', 'starts_at'], "idx_sessions_venue_start");
            $table->unique(['public_id'], "uq_sessions_public_id");
        });

        Schema::create("inventory_items", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('session_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('seat_id')->nullable();
            $table->unsignedBigInteger('standing_zone_id')->nullable();
            $table->bigInteger('price_amount');
            $table->char('currency', 3);
            $table->unsignedInteger('capacity')->default(1);
            $table->integer('available_quantity')->default(1);
            $table->string('status', 32);
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['session_id', 'status'], "idx_inventory_session_status");
            $table->unique(['public_id'], "uq_inventory_public_id");
            $table->unique(['session_id', 'seat_id'], "uq_inventory_session_seat");
            $table->unique(['session_id', 'standing_zone_id'], "uq_inventory_session_standing");
        });

        Schema::create("carts", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('session_id');
            $table->string('status', 32);
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['session_id', 'status'], "idx_carts_session_status");
            $table->index(['user_id', 'status'], "idx_carts_user_status");
            $table->unique(['public_id'], "uq_carts_public_id");
        });

        Schema::create("cart_items", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('total_price');
            $table->json('seat_snapshot_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['cart_id', 'inventory_item_id'], "uq_cart_inventory");
        });

        Schema::create("seat_holds", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('inventory_item_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('cart_id');
            $table->unsignedInteger('quantity');
            $table->dateTime('expires_at', 6);
            $table->dateTime('released_at', 6)->nullable();
            $table->dateTime('converted_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['cart_id'], "idx_holds_cart");
            $table->index(['expires_at'], "idx_holds_expire");
            $table->index(['inventory_item_id', 'expires_at'], "idx_holds_inventory_expire");
            $table->unique(['public_id'], "uq_hold_public_id");
        });

        Schema::create("orders", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->string('order_number', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('organization_id');
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->string('payment_status', 32);
            $table->string('customer_email', 255);
            $table->string('customer_phone', 50)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('paid_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('updated_at', 6);
            $table->unsignedBigInteger('promo_code_id')->nullable();
            $table->index(['organization_id', 'status'], "idx_orders_org_status");
            $table->index(['payment_status'], "idx_orders_payment_status");
            $table->index(['promo_code_id'], "idx_orders_promo");
            $table->index(['user_id', 'created_at'], "idx_orders_user_time");
            $table->unique(['order_number'], "uq_orders_number");
            $table->unique(['public_id'], "uq_orders_public_id");
        });

        Schema::create("order_items", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->string('event_title_snapshot', 500);
            $table->string('session_title_snapshot', 500)->nullable();
            $table->string('venue_title_snapshot', 500)->nullable();
            $table->json('seat_snapshot_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['inventory_item_id'], "idx_order_items_inventory");
            $table->index(['order_id'], "idx_order_items_order");
        });

        Schema::create("promo_codes", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('code', 64);
            $table->string('discount_type', 16);
            $table->bigInteger('value_amount')->default(0);
            $table->decimal('value_percent', 5, 2)->default(0.00);
            $table->char('currency', 3);
            $table->string('scope', 32);
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('event_category_id')->nullable();
            $table->bigInteger('min_order_amount')->default(0);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->string('status', 32);
            $table->dateTime('valid_from', 6)->nullable();
            $table->dateTime('valid_until', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['scope', 'event_id'], "idx_promo_codes_scope_event");
            $table->index(['valid_from', 'valid_until'], "idx_promo_codes_window");
            $table->unique(['organization_id', 'code'], "uq_promo_codes_org_code");
            $table->unique(['public_id'], "uq_promo_codes_public_id");
        });

        Schema::create("promo_code_redemptions", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('promo_code_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->bigInteger('discount_amount')->default(0);
            $table->dateTime('redeemed_at', 6);
            $table->index(['promo_code_id'], "idx_promo_redemptions_code");
            $table->index(['user_id'], "idx_promo_redemptions_user");
            $table->unique(['order_id', 'promo_code_id'], "uq_promo_redemptions_order_code");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
