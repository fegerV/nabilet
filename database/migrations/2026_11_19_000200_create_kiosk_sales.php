<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Продажи information-киоска.
 *
 * Отдельная таблица, а не колонка на заказе: заказ живёт по своим правилам
 * (email, промокоды, холды), а киоску нужна собственная идемпотентность —
 * `client_sale_id` генерируется устройством ДО запроса и переживает обрыв сети.
 * UNIQUE (kiosk_device_id, client_sale_id) делает повторный «Продать» после
 * таймаута безопасным: второй запрос вернёт ту же продажу, а не второй билет.
 *
 * `qr_payload` заполняется ТОЛЬКО после подтверждения оплаты вебхуком.
 * Киоск не подписывает QR сам: секрет подписи остаётся на сервере.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kiosk_sales')) {
            return;
        }

        Schema::create('kiosk_sales', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('kiosk_device_id');

            $table->char('client_sale_id', 36);
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->char('order_public_id', 26)->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();

            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('RUB');

            $table->string('customer_email', 255)->nullable();
            $table->string('holder_name', 255)->nullable();
            $table->string('qr_payload', 512)->nullable();

            $table->char('idempotency_key', 96)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->dateTime('paid_at', 6)->nullable();
            $table->dateTime('issued_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['public_id'], 'uq_kiosk_sales_public_id');
            $table->unique(['kiosk_device_id', 'client_sale_id'], 'uq_kiosk_sale_client');
            $table->unique(['idempotency_key'], 'uq_kiosk_sale_idempotency');
            $table->index(['organization_id'], 'idx_kiosk_sales_org');
            $table->index(['kiosk_device_id', 'status'], 'idx_kiosk_sales_device_status');
            $table->index(['order_id'], 'idx_kiosk_sales_order');
        });
    }

    public function down(): void
    {
        // Необратимо: история продаж киоска не удаляется миграцией.
    }
};
