<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фикс B2: витрина собирает имя покупателя, но в схеме orders не было колонки
 * customer_name — данные формы терялись. Теперь checkout пишет имя/телефон/email
 * в заказ, а TicketService берёт holder_name из заказа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_name', 255)->nullable()->after('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('customer_name');
        });
    }
};
