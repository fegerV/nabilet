<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix D5 («корзина на сеанс») и разрыв «заказ ↔ корзина/холды».
 *
 * 1. carts.cart_token — идентификатор покупателя (гостевой токен браузера либо
 *    публичный id пользователя). Корзина ключуется парой (cart_token, session_id),
 *    а не только сеансом: два параллельных покупателя одного сеанса больше не
 *    попадают в одну корзину.
 *
 * 2. orders.cart_id — заказ помнит корзину, из которой вырос. Это чинит
 *    PaymentService::validateHoldsForOrder(), который искал cart_id у order_items
 *    (колонки нет) и потому никогда не валидировал холды при оплате.
 *
 * Существующие строки: cart_token заполняется детерминированным значением из
 * id+session_id, чтобы миграция не ломала данные; старые корзины остаются
 * читаемыми.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('cart_token', 64)->nullable()->after('user_id');
        });

        // Детерминированный токен для существующих корзин (не пустой — иначе
        // уникальный индекс «съест» вторую строку с NULL в MySQL допустимо, но
        // семантика «без токена» лучше выражается явными legacy-значениями).
        \Illuminate\Support\Facades\DB::statement(
            "UPDATE carts SET cart_token = CONCAT('legacy-', id, '-', session_id) WHERE cart_token IS NULL"
        );

        Schema::table('carts', function (Blueprint $table): void {
            $table->unique(['cart_token', 'session_id', 'status'], 'uq_carts_token_session_status');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('cart_id')->nullable()->after('promo_code_id');
            $table->index(['cart_id'], 'idx_orders_cart');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique('uq_carts_token_session_status');
            $table->dropColumn('cart_token');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_cart');
            $table->dropColumn('cart_id');
        });
    }
};
