<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Неизменяемый снимок оформления на момент выпуска билета (аудит 2026-11-26).
 *
 * Проданный билет не должен менять дизайн, если администратор позже правит
 * шаблон, отключает его или удаляет. Динамические данные (дата, площадка,
 * цена) продолжают читаться из текущего сеанса/заказа; подписанный QR-пейлоад
 * не меняется никогда.
 *
 * Храним снимок на order_items — физической таблице билета — и открываем его
 * через VIEW tickets. Старые строки получают NULL и продолжают работать через
 * фолбэк на живой шаблон (обратная совместимость).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'template_snapshot_json')) {
            Schema::table('order_items', static function (Blueprint $table): void {
                $table->json('template_snapshot_json')->nullable()->after('qr_payload');
            });
        }

        DB::statement('DROP VIEW IF EXISTS tickets');
        DB::statement(<<<'SQL'
            CREATE VIEW tickets AS
            SELECT oi.id,
                   oi.public_id,
                   oi.ticket_number,
                   oi.ticket_index,
                   oi.order_id,
                   oi.id AS order_item_id,
                   oi.organization_id,
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
                   oi.template_snapshot_json,
                   oi.issued_at,
                   oi.used_at,
                   oi.cancelled_at,
                   oi.refunded_at,
                   oi.expired_at,
                   oi.created_at,
                   oi.created_at AS updated_at,
                   oi.revoked_at,
                   oi.revoked_reason
            FROM order_items oi
            WHERE oi.ticket_number IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS tickets');
        DB::statement(<<<'SQL'
            CREATE VIEW tickets AS
            SELECT oi.id,
                   oi.public_id,
                   oi.ticket_number,
                   oi.ticket_index,
                   oi.order_id,
                   oi.id AS order_item_id,
                   oi.organization_id,
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
                   oi.cancelled_at,
                   oi.refunded_at,
                   oi.expired_at,
                   oi.created_at,
                   oi.created_at AS updated_at,
                   oi.revoked_at,
                   oi.revoked_reason
            FROM order_items oi
            WHERE oi.ticket_number IS NOT NULL
        SQL);

        Schema::table('order_items', static function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'template_snapshot_json')) {
                $table->dropColumn('template_snapshot_json');
            }
        });
    }
};
