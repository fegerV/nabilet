<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints — the invariants the database must enforce
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * Raw SQL on purpose: Blueprint has no portable CHECK API, and these
 * constraints are the whole point of migrations/009 hardening.
 *
 * PORTABLE BY DESIGN — MySQL/MariaDB AND PostgreSQL.
 *   The spec targets MySQL, but the running instance is PostgreSQL. Every
 *   expression below is standard SQL, so there is no reason to withhold the
 *   contract on PostgreSQL: refusing to apply it there left the deployment
 *   enforcing none of these invariants while `migrate:status` still said "Ran".
 *   Only drivers that cannot add a CHECK to an existing table (SQLite needs a
 *   table rebuild) are skipped, and they are skipped loudly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsCheckConstraints()) {
            // Skipping is a real decision, so it must be audible: a migration that
            // returns early still counts as Ran, and a green migration table over
            // an unenforced contract is the failure mode this whole file exists to
            // prevent.
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED add_check_constraints: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. Every constraint below (status enums,\n"
                . "    non-negative money, inventory target exclusivity, ticket\n"
                . "    terminal-state exclusivity) is NOT enforced. migrate:status will\n"
                . "    still show this migration as Ran. Do not run production on this\n"
                . "    driver without an equivalent guard.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        DB::statement("ALTER TABLE cart_items ADD CONSTRAINT ck_cart_items_quantity CHECK (quantity > 0)");
        DB::statement("ALTER TABLE hall_schema_versions ADD CONSTRAINT ck_schema_status CHECK (status IN ('draft','published','archived'))");
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT ck_inventory_available_qty CHECK (available_quantity BETWEEN 0 AND capacity)");
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT ck_inventory_price CHECK (price_amount >= 0)");
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT ck_inventory_seat_capacity CHECK (type <> 'seat' OR capacity = 1)");
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT ck_inventory_target CHECK ((type = 'seat'     AND seat_id IS NOT NULL     AND standing_zone_id IS NULL)
    OR (type = 'standing' AND standing_zone_id IS NOT NULL AND seat_id IS NULL))");
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT ck_inventory_type CHECK (type IN ('seat','standing'))");
        DB::statement("ALTER TABLE media_assets ADD CONSTRAINT ck_media_assets_size CHECK (size_bytes >= 0)");
        DB::statement("ALTER TABLE media_links ADD CONSTRAINT ck_media_links_position CHECK (position >= 0)");
        DB::statement("ALTER TABLE offline_bundles ADD CONSTRAINT ck_offline_bundles_counts CHECK (ticket_count >= 0 AND revoked_count >= 0)");
        DB::statement("ALTER TABLE offline_bundles ADD CONSTRAINT ck_offline_bundles_window CHECK (expires_at IS NULL OR expires_at >= generated_at)");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT ck_order_items_amounts CHECK (unit_price >= 0 AND total_amount >= 0)");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT ck_order_items_quantity CHECK (quantity > 0)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_amounts CHECK (subtotal_amount >= 0 AND discount_amount >= 0 AND fee_amount >= 0 AND total_amount >= 0)");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_status CHECK (status IN (
    'pending','awaiting_payment','payment_failed','paid','cancelled','expired',
    'partially_refunded','refunded'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT ck_payments_amount CHECK (amount >= 0)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT ck_payments_status CHECK (status IN (
    'pending','waiting_for_capture','succeeded','canceled','failed'))");
        DB::statement("ALTER TABLE promo_code_redemptions ADD CONSTRAINT ck_promo_redemptions_amount CHECK (discount_amount >= 0)");
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT ck_promo_codes_amounts CHECK (value_amount >= 0 AND min_order_amount >= 0)");
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT ck_promo_codes_limit CHECK (per_user_limit >= 1)");
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT ck_promo_codes_percent CHECK (value_percent >= 0 AND value_percent <= 100)");
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT ck_promo_codes_scope CHECK (scope IN ('all','event','category','first_purchase'))");
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT ck_promo_codes_type CHECK (discount_type IN ('fixed','percent'))");
        DB::statement("ALTER TABLE promo_codes ADD CONSTRAINT ck_promo_codes_window CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT ck_refunds_amount CHECK (amount >= 0)");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT ck_refunds_status CHECK (status IN ('requested','processing','succeeded','failed'))");
        DB::statement("ALTER TABLE seat_holds ADD CONSTRAINT ck_holds_quantity CHECK (quantity > 0)");
        DB::statement("ALTER TABLE seats ADD CONSTRAINT ck_seats_status CHECK (status IN ('active','blocked','disabled'))");
        DB::statement("ALTER TABLE seats ADD CONSTRAINT ck_seats_type CHECK (type IN ('standard','vip','wheelchair','companion','custom'))");
        DB::statement("ALTER TABLE sectors ADD CONSTRAINT ck_sectors_type CHECK (type IN ('seated','standing','mixed'))");
        DB::statement("ALTER TABLE sessions ADD CONSTRAINT ck_sessions_status CHECK (status IN (
    'draft','scheduled','on_sale','sold_out','closed','completed','cancelled'))");
        DB::statement("ALTER TABLE standing_zones ADD CONSTRAINT ck_standing_capacity CHECK (capacity > 0)");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT ck_tickets_index CHECK (ticket_index >= 1)");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT ck_tickets_status CHECK (status IN ('issued','used','cancelled','refunded','expired','revoked'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT ck_tickets_terminal_exclusive CHECK (NOT (used_at IS NOT NULL AND (cancelled_at IS NOT NULL OR refunded_at IS NOT NULL)))");
    }

    /**
     * Drivers that can add a CHECK constraint to an existing table.
     *
     * PostgreSQL is included deliberately: its CHECK syntax is identical for every
     * expression in this file, and the alternative — silently enforcing nothing on
     * the database the application actually runs against — is the worse failure.
     * SQLite is excluded because ALTER TABLE cannot add a constraint without
     * rebuilding the table.
     */
    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }

    public function down(): void
    {
        // Irreversible by design: dropping an invariant weakens the database.
    }
};
