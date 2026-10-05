<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the column DEFAULTs that the migration generator silently dropped.
 *
 * WHY THIS EXISTS
 *   `tools/gen-migrations.py` read `information_schema.COLUMN_DEFAULT`, which returns
 *   a string default *without* the quotes the spec text carries — `active`, not
 *   `'active'`. `php_default()` only recognised the quoted form and returned null for
 *   anything else, so every string default in the schema was dropped on the way from
 *   `nabilet_core_spec/migrations.sql` into `database/migrations/`. Numeric defaults
 *   took the `-?\d+` branch and survived, which is why 41 of them were fine and the
 *   schema looked mostly correct.
 *
 *   The result was 44 columns across 36 tables with no DEFAULT. That is not cosmetic:
 *   `users.status`, `users.locale` and `users.timezone` are `NOT NULL` with a default
 *   in the spec, so an INSERT that does not name them — which is every registration —
 *   fails with MySQL 1364 ("Field 'status' doesn't have a default value").
 *
 * WHY A NEW MIGRATION RATHER THAN AN EDIT
 *   The eight `2026_09_20_0001..0008_*` files have already run everywhere. They were
 *   corrected for fresh installs, but an installation that already exists needs the
 *   defaults applied to the tables it has. This does that, and is a no-op on a schema
 *   built from the corrected files.
 *
 *   The list is the full set of defaults the spec declares, not just the 44 that were
 *   missing, so the file states the intended end state rather than a diff against a
 *   particular broken revision. `ALTER ... SET DEFAULT` is metadata-only and cheap.
 *
 * `tools/verify-migrations.php` now diffs defaults as well, so a regression here is
 * caught in CI instead of at the first INSERT.
 */
return new class extends Migration
{
    /**
     * table.column => the DEFAULT literal, exactly as the spec writes it.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
            'ab_experiments.status' => "'draft'",
            'carts.status' => "'active'",
            'checkin_devices.platform' => "'android'",
            'checkin_devices.status' => "'active'",
            'embed_domains.active' => "1",
            'event_categories.status' => "'active'",
            'events.status' => "'draft'",
            'hall_rows.currency' => "'RUB'",
            'hall_rows.price_amount' => "0",
            'hall_rows.rotation' => "0.000",
            'hall_schema_versions.status' => "'draft'",
            'hall_tables.rotation' => "0.000",
            'hall_tables.x' => "0.000",
            'hall_tables.y' => "0.000",
            'halls.status' => "'active'",
            'inventory_items.available_quantity' => "1",
            'inventory_items.capacity' => "1",
            'inventory_items.currency' => "'RUB'",
            'inventory_items.status' => "'available'",
            'ip_rules.active' => "1",
            'ip_rules.scope' => "'global'",
            'media_assets.disk' => "'local'",
            'media_assets.size_bytes' => "0",
            'media_links.position' => "0",
            'media_links.role' => "'gallery'",
            'modules.enabled' => "1",
            'notification_templates.active' => "1",
            'notification_templates.locale' => "'ru'",
            'notifications.status' => "'queued'",
            'offline_bundles.revoked_count' => "0",
            'offline_bundles.schema_version' => "1",
            'offline_bundles.status' => "'generated'",
            'offline_bundles.ticket_count' => "0",
            'order_items.discount_amount' => "0",
            'order_items.fee_amount' => "0",
            'orders.currency' => "'RUB'",
            'orders.discount_amount' => "0",
            'orders.fee_amount' => "0",
            'orders.payment_status' => "'pending'",
            'orders.status' => "'pending'",
            'organizations.status' => "'active'",
            'pages.status' => "'draft'",
            'payments.currency' => "'RUB'",
            'payments.status' => "'pending'",
            'privacy_requests.status' => "'requested'",
            'promo_code_redemptions.discount_amount' => "0",
            'promo_codes.currency' => "'RUB'",
            'promo_codes.discount_type' => "'percent'",
            'promo_codes.min_order_amount' => "0",
            'promo_codes.per_user_limit' => "1",
            'promo_codes.redemptions_count' => "0",
            'promo_codes.scope' => "'all'",
            'promo_codes.status' => "'active'",
            'promo_codes.value_amount' => "0",
            'promo_codes.value_percent' => "0.00",
            'redirects.active' => "1",
            'redirects.status_code' => "301",
            'refunds.currency' => "'RUB'",
            'refunds.status' => "'requested'",
            'seats.rotation' => "0.000",
            'seats.status' => "'active'",
            'seats.type' => "'standard'",
            'seats.x' => "0.000",
            'seats.y' => "0.000",
            'sectors.type' => "'seated'",
            'sectors.x' => "0.000",
            'sectors.y' => "0.000",
            'seo_meta.locale' => "'ru'",
            'sessions.status' => "'draft'",
            'sessions.timezone' => "'UTC'",
            'settings.encrypted' => "0",
            'standing_zones.currency' => "'RUB'",
            'standing_zones.price_amount' => "0",
            'ticket_templates.active' => "1",
            'ticket_templates.format' => "'mobile'",
            'tickets.qr_version' => "1",
            'tickets.status' => "'issued'",
            'tickets.ticket_index' => "1",
            'users.locale' => "'ru'",
            'users.status' => "'active'",
            'users.timezone' => "'UTC'",
            'venues.status' => "'active'",
            'webhook_deliveries.attempt' => "1",
            'webhooks.active' => "1",
            'webhooks.retry_limit' => "10",
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $target => $literal) {
            [$table, $column] = explode('.', $target, 2);

            // A partial schema (a module's tables not yet created, a test that builds
            // one table) must not turn into a failed migration: the default is applied
            // where the column exists and skipped where it does not.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` ALTER COLUMN `%s` SET DEFAULT %s',
                $table,
                $column,
                $literal,
            ));
        }
    }

    public function down(): void
    {
        // Irreversible by design. The reverse — `DROP DEFAULT` — would restore a
        // schema the spec forbids, and every one of these defaults is load-bearing for
        // an INSERT that omits the column. Rolling back is not the safe direction here.
    }
};
