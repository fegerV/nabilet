<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонки напоминаний о брошенном: одна на заказ, вторая на корзину.
 *
 * ЗАЧЕМ
 *   Единственное, чего «брошенной корзине» не хватает, — возврата покупателя
 *   (инвентарь защищён sweeper'ом, TTL истекает, места возвращаются). Возврат
 *   = письмо, а письмо можно отправить только:
 *     - по незавершённому ЗАКАЗУ — там уже есть `customer_email`;
 *     - по корзине АВТОРИЗОВАННОГО покупателя — email живёт в аккаунте.
 *   Гостевую корзину до чекаута напомнить нечем: контакта ещё не существует.
 *
 * ПОЧЕМУ КОЛОНКА, А НЕ ТАБЛИЦА-ЖУРНАЛ
 *   Повторов быть не должно (спам = отписка = потерянная продажа), флаг
 *   «напоминали» хранится рядом с объектом и проверяется тем же запросом,
 *   который его выбирает (`WHERE reminder_sent_at IS NULL`). Отдельная таблица
 *   дала бы UNIQUE и JOIN — сложность без gains.
 *
 * NULL = «ещё не напоминали». Ставится только после фактической постановки
 * письма в очередь; сбой транспорта оставляет NULL — следующий проход
 * напомнит (обе команды идемпотентны по этому флагу).
 *
 * Зеркала: `nabilet_core_spec/migrations/012_application_domain.sql`,
 * консолидированные `migrations.sql` (корень и бандл).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->datetime('reminder_sent_at')->nullable()->after('paid_at');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->datetime('reminder_sent_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
