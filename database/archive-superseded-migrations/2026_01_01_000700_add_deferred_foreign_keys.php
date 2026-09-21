<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred foreign keys.
 *
 * WHY THIS EXISTS: the domain has genuine circular references between aggregates.
 *
 *     inventory_items.current_hold_id  →  seat_holds.id
 *     seat_holds.inventory_item_id     →  inventory_items.id
 *
 *     carts.converted_order_id         →  orders.id
 *     orders  (created from a cart)
 *
 *     seat_holds.converted_order_id    →  orders.id
 *
 * A hold points at the item it holds, and the item points back at its active hold.
 * Neither table can be created first with its constraint already in place, so the
 * back-references are declared as plain columns in the create migrations and the
 * constraints are attached here, once both sides exist.
 *
 * This is preferable to dropping the constraints: without them a deleted hold would
 * leave inventory permanently pointing at a non-existent hold, and the seat would
 * appear "held" forever with no way to release it.
 *
 * All of these are nullOnDelete — the pointer is a convenience cache of current
 * state, not the owner of the relationship. The owning reference always points from
 * the hold/cart to the item/order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_holds', function (Blueprint $table): void {
            $table->foreign('cart_id', 'seat_holds_cart_fk')
                ->references('id')->on('carts')->nullOnDelete();

            $table->foreign('converted_order_id', 'seat_holds_order_fk')
                ->references('id')->on('orders')->nullOnDelete();
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->foreign('current_hold_id', 'inventory_current_hold_fk')
                ->references('id')->on('seat_holds')->nullOnDelete();

            $table->foreign('order_item_id', 'inventory_order_item_fk')
                ->references('id')->on('order_items')->nullOnDelete();
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreign('converted_order_id', 'carts_order_fk')
                ->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign('carts_order_fk');
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropForeign('inventory_order_item_fk');
            $table->dropForeign('inventory_current_hold_fk');
        });

        Schema::table('seat_holds', function (Blueprint $table): void {
            $table->dropForeign('seat_holds_order_fk');
            $table->dropForeign('seat_holds_cart_fk');
        });
    }
};
