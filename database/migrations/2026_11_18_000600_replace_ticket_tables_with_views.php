<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Финальный размен: tickets и ticket_scans больше не таблицы.
 *
 * Вся информация о билете и сканированиях хранится в order_items и
 * order_item_scans. Старые имена остаются как updatable VIEW, поэтому
 * существующие читатели (API, письма, QR, чек-ин) продолжают работать без
 * изменений — но физических таблиц tickets/ticket_scans больше нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_scans')) {
            Schema::table('ticket_scans', function (Blueprint $table): void {
                $table->dropForeign('fk_ticket_scans_ticket');
            });
            Schema::drop('ticket_scans');
        }

        if (Schema::hasTable('tickets')) {
            Schema::drop('tickets');
        }

        DB::statement(<<<'SQL'
            CREATE VIEW tickets AS
            SELECT oi.id,
                   oi.public_id,
                   oi.ticket_number,
                   oi.ticket_index,
                   oi.order_id,
                   oi.id AS order_item_id,
                   oi.event_id,
                   oi.session_id,
                   oi.inventory_item_id,
                   oi.seat_id,
                   oi.standing_zone_id,
                   oi.holder_name,
                   oi.ticket_status AS status,
                   oi.qr_version,
                   oi.qr_token_hash,
                   oi.qr_payload,
                   oi.issued_at,
                   oi.used_at,
                   CAST(NULL AS DATETIME(6)) AS cancelled_at,
                   oi.refunded_at,
                   CAST(NULL AS DATETIME(6)) AS expired_at,
                   oi.created_at,
                   oi.created_at AS updated_at,
                   oi.revoked_at,
                   oi.revoked_reason
            FROM order_items oi
        SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW ticket_scans AS
            SELECT os.id,
                   os.public_id,
                   os.order_item_id AS ticket_id,
                   os.session_id,
                   os.device_id,
                   os.client_scan_id,
                   os.mode,
                   os.result,
                   os.scanned_at,
                   os.latitude,
                   os.longitude,
                   os.metadata_json,
                   os.created_at
            FROM order_item_scans os
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ticket_scans');
        DB::statement('DROP VIEW IF EXISTS tickets');

        throw new RuntimeException('ticket tables were replaced by views; restore from a backup.');
    }
};
