<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A6: tickets требует session_id и event_id (NOT NULL). У orders их не было —
 * выпуск билетов после оплаты был невозможен. Резолвим их из корзины заказа
 * (carts.session_id → sessions.event_id) и проставляем существующим заказам,
 * где это возможно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('event_id')->nullable()->after('session_id');
            $table->index(['session_id'], 'idx_orders_session');
            $table->index(['event_id'], 'idx_orders_event');
        });

        // Backfill: заказ помнит корзину → корзина помнит сеанс → сеанс событие.
        DB::statement(
            'UPDATE orders o
                JOIN carts c ON c.id = o.cart_id
                JOIN sessions s ON s.id = c.session_id
               SET o.session_id = c.session_id, o.event_id = s.event_id
             WHERE o.session_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_session');
            $table->dropIndex('idx_orders_event');
            $table->dropColumn(['session_id', 'event_id']);
        });
    }
};
