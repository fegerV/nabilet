<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VIEW билетов должны нести organization_id — TenantScoped фильтрует по нему
 * напрямую. Добавляем колонку в базовые таблицы и пересоздаём представления.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('order_id');
                $table->index(['organization_id'], 'idx_order_items_org');
            }
        });
        DB::statement(<<<'SQL'
            UPDATE order_items oi
            JOIN orders o ON o.id = oi.order_id
            SET oi.organization_id = o.organization_id
            WHERE oi.organization_id IS NULL
        SQL);

        Schema::table('order_items', function (Blueprint $table): void {
            foreach (['cancelled_at', 'expired_at'] as $column) {
                if (! Schema::hasColumn('order_items', $column)) {
                    $table->dateTime($column, 6)->nullable()->after('revoked_at');
                }
            }
        });

        Schema::table('order_item_scans', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_item_scans', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('order_item_id');
                $table->index(['organization_id'], 'idx_order_item_scans_org');
            }
        });
        DB::statement(<<<'SQL'
            UPDATE order_item_scans os
            JOIN orders o ON o.id = (SELECT order_id FROM order_items WHERE id = os.order_item_id)
            SET os.organization_id = o.organization_id
            WHERE os.organization_id IS NULL
        SQL);

        DB::statement('DROP VIEW IF EXISTS ticket_scans');
        DB::statement('DROP VIEW IF EXISTS tickets');

        DB::statement(<<<'SQL'
            CREATE VIEW tickets AS
            SELECT oi.id, oi.public_id, oi.ticket_number, oi.ticket_index,
                   oi.order_id, oi.id AS order_item_id,
                   oi.organization_id, oi.event_id, oi.session_id, oi.inventory_item_id,
                   oi.seat_id, oi.standing_zone_id, oi.holder_name,
                   oi.ticket_status AS status, oi.qr_version, oi.qr_token_hash, oi.qr_payload,
                   oi.issued_at, oi.used_at, oi.cancelled_at,
                   oi.refunded_at, oi.expired_at,
                   oi.created_at, oi.created_at AS updated_at,
                   oi.revoked_at, oi.revoked_reason
            FROM order_items oi
            WHERE oi.ticket_number IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW ticket_scans AS
            SELECT os.id, os.public_id, os.order_item_id AS ticket_id,
                   os.organization_id, os.session_id, os.device_id, os.client_scan_id,
                   os.mode, os.result, os.scanned_at, os.latitude, os.longitude,
                   os.metadata_json, os.created_at
            FROM order_item_scans os
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('ticket view tenant columns are not reversible.');
    }
};
