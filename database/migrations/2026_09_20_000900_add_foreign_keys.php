<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred foreign keys for the whole schema
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * Every foreign key is applied here, after all tables exist. Declaring
 * them inline would make creation order significant and break `migrate:fresh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table("ab_assignments", function (Blueprint $table): void {
            $table->foreign('experiment_id', "fk_ab_assignments_experiment")->references('id')->on('ab_experiments')->cascadeOnDelete();
            $table->foreign('user_id', "fk_ab_assignments_user")->references('id')->on('users')->nullOnDelete();
            $table->foreign('variant_id', "fk_ab_assignments_variant")->references('id')->on('ab_variants')->cascadeOnDelete();
        });

        Schema::table("ab_experiments", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_ab_experiments_org")->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table("ab_metrics", function (Blueprint $table): void {
            $table->foreign('experiment_id', "fk_ab_metrics_experiment")->references('id')->on('ab_experiments')->cascadeOnDelete();
            $table->foreign('variant_id', "fk_ab_metrics_variant")->references('id')->on('ab_variants')->cascadeOnDelete();
        });

        Schema::table("ab_variants", function (Blueprint $table): void {
            $table->foreign('experiment_id', "fk_ab_variants_experiment")->references('id')->on('ab_experiments')->cascadeOnDelete();
        });

        Schema::table("analytics_events", function (Blueprint $table): void {
            $table->foreign('event_id', "fk_analytics_event")->references('id')->on('events')->nullOnDelete();
            $table->foreign('session_id', "fk_analytics_session")->references('id')->on('sessions')->nullOnDelete();
            $table->foreign('user_id', "fk_analytics_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("api_keys", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_api_keys_org")->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table("audit_logs", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_audit_org")->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('user_id', "fk_audit_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("cart_items", function (Blueprint $table): void {
            $table->foreign('cart_id', "fk_cart_items_cart")->references('id')->on('carts')->cascadeOnDelete();
            $table->foreign('inventory_item_id', "fk_cart_items_inventory")->references('id')->on('inventory_items')->restrictOnDelete();
        });

        Schema::table("carts", function (Blueprint $table): void {
            $table->foreign('session_id', "fk_carts_session")->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('user_id', "fk_carts_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("checkin_devices", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_checkin_devices_org")->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table("consents", function (Blueprint $table): void {
            $table->foreign('user_id', "fk_consents_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("embed_domains", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_embed_domains_org")->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table("event_translations", function (Blueprint $table): void {
            $table->foreign('event_id', "fk_event_translations_event")->references('id')->on('events')->cascadeOnDelete();
        });

        Schema::table("events", function (Blueprint $table): void {
            $table->foreign('category_id', "fk_events_category")->references('id')->on('event_categories')->nullOnDelete();
            $table->foreign('organization_id', "fk_events_org")->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::table("hall_rows", function (Blueprint $table): void {
            $table->foreign('sector_id', "fk_hall_rows_sector")->references('id')->on('sectors')->cascadeOnDelete();
        });

        Schema::table("hall_schema_versions", function (Blueprint $table): void {
            $table->foreign('hall_id', "fk_schema_hall")->references('id')->on('halls')->cascadeOnDelete();
        });

        Schema::table("hall_tables", function (Blueprint $table): void {
            $table->foreign('sector_id', "fk_hall_tables_sector")->references('id')->on('sectors')->cascadeOnDelete();
        });

        Schema::table("halls", function (Blueprint $table): void {
            $table->foreign('venue_id', "fk_halls_venue")->references('id')->on('venues')->cascadeOnDelete();
        });

        Schema::table("heatmap_events", function (Blueprint $table): void {
            $table->foreign('user_id', "fk_heatmap_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("inventory_items", function (Blueprint $table): void {
            $table->foreign('seat_id', "fk_inventory_seat")->references('id')->on('seats')->restrictOnDelete();
            $table->foreign('session_id', "fk_inventory_session")->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('standing_zone_id', "fk_inventory_standing")->references('id')->on('standing_zones')->restrictOnDelete();
        });

        Schema::table("login_logs", function (Blueprint $table): void {
            $table->foreign('user_id', "fk_login_logs_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("media_assets", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_media_assets_org")->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('uploaded_by', "fk_media_assets_uploader")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("media_links", function (Blueprint $table): void {
            $table->foreign('media_asset_id', "fk_media_links_asset")->references('id')->on('media_assets')->cascadeOnDelete();
        });

        Schema::table("notifications", function (Blueprint $table): void {
            $table->foreign('user_id', "fk_notifications_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("offline_bundles", function (Blueprint $table): void {
            $table->foreign('checkin_device_id', "fk_offline_bundles_device")->references('id')->on('checkin_devices')->cascadeOnDelete();
            $table->foreign('event_id', "fk_offline_bundles_event")->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('organization_id', "fk_offline_bundles_org")->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('session_id', "fk_offline_bundles_session")->references('id')->on('sessions')->cascadeOnDelete();
        });

        Schema::table("order_items", function (Blueprint $table): void {
            $table->foreign('inventory_item_id', "fk_order_items_inventory")->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('order_id', "fk_order_items_order")->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::table("orders", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_orders_org")->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('promo_code_id', "fk_orders_promo")->references('id')->on('promo_codes')->nullOnDelete();
            $table->foreign('user_id', "fk_orders_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("page_translations", function (Blueprint $table): void {
            $table->foreign('page_id', "fk_page_translations_page")->references('id')->on('pages')->cascadeOnDelete();
        });

        Schema::table("pages", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_pages_org")->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table("payment_transactions", function (Blueprint $table): void {
            $table->foreign('payment_id', "fk_payment_transactions_payment")->references('id')->on('payments')->cascadeOnDelete();
        });

        Schema::table("payments", function (Blueprint $table): void {
            $table->foreign('order_id', "fk_payments_order")->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::table("privacy_requests", function (Blueprint $table): void {
            $table->foreign('user_id', "fk_privacy_requests_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("promo_code_redemptions", function (Blueprint $table): void {
            $table->foreign('order_id', "fk_promo_redemptions_order")->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('promo_code_id', "fk_promo_redemptions_code")->references('id')->on('promo_codes')->restrictOnDelete();
            $table->foreign('user_id', "fk_promo_redemptions_user")->references('id')->on('users')->nullOnDelete();
        });

        Schema::table("promo_codes", function (Blueprint $table): void {
            $table->foreign('event_category_id', "fk_promo_codes_category")->references('id')->on('event_categories')->cascadeOnDelete();
            $table->foreign('event_id', "fk_promo_codes_event")->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('organization_id', "fk_promo_codes_org")->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table("refunds", function (Blueprint $table): void {
            $table->foreign('order_id', "fk_refunds_order")->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('payment_id', "fk_refunds_payment")->references('id')->on('payments')->restrictOnDelete();
        });

        Schema::table("role_permissions", function (Blueprint $table): void {
            $table->foreign('permission_id', "fk_rp_permission")->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id', "fk_rp_role")->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::table("seat_holds", function (Blueprint $table): void {
            $table->foreign('cart_id', "fk_holds_cart")->references('id')->on('carts')->cascadeOnDelete();
            $table->foreign('inventory_item_id', "fk_holds_inventory")->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('session_id', "fk_holds_session")->references('id')->on('sessions')->cascadeOnDelete();
        });

        Schema::table("seats", function (Blueprint $table): void {
            $table->foreign('row_id', "fk_seats_row")->references('id')->on('hall_rows')->cascadeOnDelete();
        });

        Schema::table("sectors", function (Blueprint $table): void {
            $table->foreign('schema_version_id', "fk_sectors_schema")->references('id')->on('hall_schema_versions')->cascadeOnDelete();
        });

        Schema::table("sessions", function (Blueprint $table): void {
            $table->foreign('event_id', "fk_sessions_event")->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('hall_id', "fk_sessions_hall")->references('id')->on('halls')->restrictOnDelete();
            $table->foreign('schema_version_id', "fk_sessions_schema")->references('id')->on('hall_schema_versions')->restrictOnDelete();
            $table->foreign('venue_id', "fk_sessions_venue")->references('id')->on('venues')->restrictOnDelete();
        });

        Schema::table("standing_zones", function (Blueprint $table): void {
            $table->foreign('sector_id', "fk_standing_sector")->references('id')->on('sectors')->cascadeOnDelete();
        });

        Schema::table("ticket_scans", function (Blueprint $table): void {
            $table->foreign('device_id', "fk_ticket_scans_device")->references('id')->on('checkin_devices')->nullOnDelete();
            $table->foreign('session_id', "fk_ticket_scans_session")->references('id')->on('sessions')->restrictOnDelete();
            $table->foreign('ticket_id', "fk_ticket_scans_ticket")->references('id')->on('tickets')->restrictOnDelete();
        });

        Schema::table("ticket_templates", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_ticket_templates_org")->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::table("tickets", function (Blueprint $table): void {
            $table->foreign('event_id', "fk_tickets_event")->references('id')->on('events')->restrictOnDelete();
            $table->foreign('inventory_item_id', "fk_tickets_inventory")->references('id')->on('inventory_items')->restrictOnDelete();
            $table->foreign('order_id', "fk_tickets_order")->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', "fk_tickets_order_item")->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('seat_id', "fk_tickets_seat")->references('id')->on('seats')->restrictOnDelete();
            $table->foreign('session_id', "fk_tickets_session")->references('id')->on('sessions')->restrictOnDelete();
            $table->foreign('standing_zone_id', "fk_tickets_standing")->references('id')->on('standing_zones')->restrictOnDelete();
        });

        Schema::table("user_organization", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_uo_org")->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('role_id', "fk_uo_role")->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('user_id', "fk_uo_user")->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table("user_roles", function (Blueprint $table): void {
            $table->foreign('granted_by', "fk_user_roles_grantor")->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id', "fk_user_roles_org")->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('role_id', "fk_user_roles_role")->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('user_id', "fk_user_roles_user")->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table("user_sessions", function (Blueprint $table): void {
            $table->foreign('user_id', "fk_user_sessions_user")->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table("venue_translations", function (Blueprint $table): void {
            $table->foreign('venue_id', "fk_venue_translations_venue")->references('id')->on('venues')->cascadeOnDelete();
        });

        Schema::table("venues", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_venues_org")->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::table("webhook_deliveries", function (Blueprint $table): void {
            $table->foreign('webhook_id', "fk_webhook_deliveries_webhook")->references('id')->on('webhooks')->cascadeOnDelete();
        });

        Schema::table("webhooks", function (Blueprint $table): void {
            $table->foreign('organization_id', "fk_webhooks_org")->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
