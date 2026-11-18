<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Чек-ин проходит по позиции заказа, а не по билету (этап 3).
 *
 * Создаём каноническую таблицу сканирований `order_item_scans` и переносим
 * историю из ticket_scans. Старая таблица остаётся на этом шаге как зеркало;
 * после перевода читателей она будет удалена вместе с tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_item_scans')) {
            return;
        }

        Schema::create('order_item_scans', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->char('client_scan_id', 36)->nullable();
            $table->string('mode', 32);
            $table->string('result', 64);
            $table->dateTime('scanned_at', 6);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['session_id', 'scanned_at'], 'idx_order_item_scans_session_time');
            $table->index(['order_item_id', 'scanned_at'], 'idx_order_item_scans_item_time');
            $table->unique(['device_id', 'client_scan_id'], 'uq_order_item_scans_client');
            $table->unique(['public_id'], 'uq_order_item_scans_public_id');
        });

        DB::statement(<<<'SQL'
            INSERT INTO order_item_scans (
                public_id, order_item_id, session_id, device_id, client_scan_id,
                mode, result, scanned_at, latitude, longitude, metadata_json, created_at
            )
            SELECT s.public_id,
                   COALESCE(oi.id, t.order_item_id) AS order_item_id,
                   s.session_id, s.device_id, s.client_scan_id,
                   s.mode, s.result, s.scanned_at, s.latitude, s.longitude,
                   s.metadata_json, s.created_at
            FROM ticket_scans s
            LEFT JOIN tickets t ON t.id = s.ticket_id
            LEFT JOIN order_items oi ON oi.public_id = t.public_id
            WHERE COALESCE(oi.id, t.order_item_id) IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_scans');
    }
};
