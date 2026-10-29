<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 1 мультитенантности: nullable `organization_id` для таблиц класса A.
 *
 * См. `docs/SECURITY-GAPS-REMEDIATION-2026-10-29.md` §3.2 (класс A). Колонка
 * добавляется NULL-ной и заполняется отдельной идемпотентной командой
 * `tenancy:backfill-organization`; переход к `NOT NULL`/FK — отдельная
 * миграция (фаза 3) только после нулевого отчёта о NULL.
 *
 * Это чисто additive DDL: откат — drop column/index. Список таблиц и ключи
 * соединений сверены со эталонной схемой `nabilet_core_spec/migrations.sql`.
 */
return new class extends Migration
{
    /**
     * Таблицы класса A: строка без организации не имеет смысла.
     * Ключ — имя таблицы, значение — пояснение источника бэкфилла (для
     * ревью); сами JOIN'ы живут в команде `tenancy:backfill-organization`.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'sessions' => 'events.organization_id (sessions.event_id)',
        'inventory_items' => 'sessions.organization_id (inventory_items.session_id)',
        'carts' => 'sessions.organization_id; иначе единственное членство user_organization',
        'cart_items' => 'carts.organization_id (cart_items.cart_id)',
        'seat_holds' => 'carts.organization_id, иначе sessions.organization_id',
        'order_items' => 'orders.organization_id (order_items.order_id)',
        'payments' => 'orders.organization_id (payments.order_id)',
        'payment_transactions' => 'payments.organization_id (payment_transactions.payment_id)',
        'refunds' => 'orders.organization_id (refunds.order_id)',
        'tickets' => 'orders.organization_id (tickets.order_id)',
        'ticket_scans' => 'tickets.organization_id, иначе sessions.organization_id',
        'halls' => 'venues.organization_id (halls.venue_id)',
        'hall_schema_versions' => 'halls.organization_id (hall_schema_versions.hall_id)',
        'sectors' => 'hall_schema_versions.organization_id (sectors.schema_version_id)',
        'hall_rows' => 'sectors.organization_id (hall_rows.sector_id)',
        'seats' => 'hall_rows→sectors.organization_id (seats.row_id)',
        'hall_tables' => 'sectors.organization_id (hall_tables.sector_id)',
        'standing_zones' => 'sectors.organization_id (standing_zones.sector_id)',
        'event_translations' => 'events.organization_id (event_translations.event_id)',
        'venue_translations' => 'venues.organization_id (venue_translations.venue_id)',
        'page_translations' => 'pages.organization_id (page_translations.page_id)',
        'promo_code_redemptions' => 'promo_codes.organization_id (promo_code_redemptions.promo_code_id)',
        'event_dates' => 'events.organization_id (event_dates.event_id)',
        'event_speakers' => 'events.organization_id (event_speakers.event_id)',
        'event_sponsors' => 'events.organization_id (event_sponsors.event_id)',
        'event_faqs' => 'events.organization_id (event_faqs.event_id)',
        'event_artists' => 'events.organization_id (event_artists.event_id)',
        'event_schedule_items' => 'events.organization_id (event_schedule_items.event_id)',
        'artist_event' => 'events.organization_id (artist_event.event_id)',
        'ab_variants' => 'ab_experiments.organization_id (ab_variants.experiment_id)',
        'ab_assignments' => 'ab_experiments.organization_id (ab_assignments.experiment_id)',
        'ab_metrics' => 'ab_experiments.organization_id (ab_metrics.experiment_id)',
        'webhook_deliveries' => 'webhooks.organization_id (webhook_deliveries.webhook_id)',
        'event_favorites' => 'events.organization_id (event_favorites.event_id)',
        'event_follows' => 'events.organization_id (event_follows.event_id)',
        'follow_notifications' => 'sessions→events.organization_id (follow_notifications.event_id)',
        'raffles' => 'нет однозначного родителя: из raffle_entries→orders; иначе ручной разбор',
        'raffle_prizes' => 'raffles.organization_id (raffle_prizes.raffle_id)',
        'raffle_entries' => 'orders.organization_id, иначе raffles.organization_id',
        'raffle_winners' => 'raffles.organization_id (raffle_winners.raffle_id)',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->unsignedBigInteger('organization_id')->nullable()->after('id');
                $t->index(['organization_id'], 'idx_' . $table . '_org');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->dropIndex('idx_' . $table . '_org');
                $t->dropColumn('organization_id');
            });
        }
    }
};
