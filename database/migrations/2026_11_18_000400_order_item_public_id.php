<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Позиция заказа получает публичный идентификатор билета.
 *
 * Это последний отсутствующий ключ, чтобы на следующем шаге заменить таблицу
 * tickets представимым (updatable) VIEW над order_items: весь существующий
 * код, читающий Ticket, продолжит работать, но физической таблицы не станет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'public_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->char('public_id', 26)->nullable()->after('id');
                $table->unique(['public_id'], 'uq_order_items_public_id');
            });
        }

        if (! Schema::hasColumn('order_items', 'seat_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('seat_id')->nullable()->after('session_id');
                $table->unsignedBigInteger('standing_zone_id')->nullable()->after('seat_id');
            });
        }

        DB::statement(<<<'SQL'
            UPDATE order_items oi
            JOIN tickets t ON t.order_item_id = oi.id
            SET oi.public_id = t.public_id,
                oi.seat_id = t.seat_id,
                oi.standing_zone_id = t.standing_zone_id
            WHERE oi.public_id IS NULL
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('order_item_public_id is not reversible; restore from a backup.');
    }
};
