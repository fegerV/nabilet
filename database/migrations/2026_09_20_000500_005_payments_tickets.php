<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: payments, payment_transactions, refunds, ticket_templates, tickets, checkin_devices, ticket_scans, offline_bundles
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - payments
 * - payment_transactions
 * - refunds
 * - ticket_templates
 * - tickets
 * - checkin_devices
 * - ticket_scans
 * - offline_bundles
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("payments", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('order_id');
            $table->string('provider', 64);
            $table->string('provider_payment_id', 255)->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->string('payment_url', 2048)->nullable();
            $table->string('idempotency_key', 255);
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('paid_at', 6)->nullable();
            $table->json('processed_webhook_events')->nullable();
            $table->index(['order_id'], "idx_payments_order");
            $table->index(['status'], "idx_payments_status");
            $table->unique(['provider', 'idempotency_key'], "uq_payments_idempotency");
            $table->unique(['provider', 'provider_payment_id'], "uq_payments_provider_id");
            $table->unique(['public_id'], "uq_payments_public_id");
        });

        Schema::create("payment_transactions", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('provider_event_id', 255)->nullable();
            $table->string('type', 64);
            $table->bigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 32)->nullable();
            $table->json('payload_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['payment_id'], "idx_payment_transactions_payment");
            $table->unique(['payment_id', 'provider_event_id'], "uq_payment_transactions_event");
        });

        Schema::create("refunds", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('reason', 500)->nullable();
            $table->string('status', 32);
            $table->string('provider_refund_id', 255)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('updated_at', 6);
            $table->index(['order_id'], "idx_refunds_order");
            $table->index(['payment_id'], "idx_refunds_payment");
            $table->unique(['provider_refund_id'], "uq_refunds_provider_id");
            $table->unique(['public_id'], "uq_refunds_public_id");
        });

        Schema::create("ticket_templates", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name', 255);
            $table->string('format', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('template_json');
            $table->boolean('active')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['organization_id'], "idx_ticket_templates_org");
            $table->unique(['public_id'], "uq_ticket_templates_public_id");
        });

        Schema::create("tickets", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->string('ticket_number', 100);
            $table->unsignedInteger('ticket_index')->default(1);
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->unsignedBigInteger('seat_id')->nullable();
            $table->unsignedBigInteger('standing_zone_id')->nullable();
            $table->string('holder_name', 255)->nullable();
            $table->string('status', 32);
            $table->unsignedSmallInteger('qr_version')->default(1);
            $table->char('qr_token_hash', 64);
            $table->dateTime('issued_at', 6);
            $table->dateTime('used_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('refunded_at', 6)->nullable();
            $table->dateTime('expired_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 100)->nullable();
            $table->index(['event_id', 'status'], "idx_tickets_event_status");
            $table->index(['session_id', 'status'], "idx_tickets_session_status");
            $table->unique(['ticket_number'], "uq_tickets_number");
            $table->unique(['order_item_id', 'ticket_index'], "uq_tickets_order_item_index");
            $table->unique(['public_id'], "uq_tickets_public_id");
            $table->unique(['qr_token_hash'], "uq_tickets_qr_hash");
        });

        Schema::create("checkin_devices", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('name', 255);
            $table->char('device_token_hash', 64);
            $table->string('platform', 32);
            $table->string('app_version', 50)->nullable();
            $table->string('status', 32);
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_checkin_devices_public_id");
            $table->unique(['device_token_hash'], "uq_checkin_devices_token");
        });

        Schema::create("ticket_scans", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('ticket_id');
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
            $table->index(['session_id', 'scanned_at'], "idx_ticket_scans_session_time");
            $table->index(['ticket_id', 'scanned_at'], "idx_ticket_scans_ticket_time");
            $table->unique(['device_id', 'client_scan_id'], "uq_ticket_scans_client");
            $table->unique(['public_id'], "uq_ticket_scans_public_id");
        });

        Schema::create("offline_bundles", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('checkin_device_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->char('bundle_hash', 64);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('public_key_fingerprint', 128)->nullable();
            $table->unsignedInteger('ticket_count')->default(0);
            $table->unsignedInteger('revoked_count')->default(0);
            $table->json('payload_json')->nullable();
            $table->string('status', 32);
            $table->dateTime('generated_at', 6);
            $table->dateTime('downloaded_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['checkin_device_id', 'generated_at'], "idx_offline_bundles_device");
            $table->index(['session_id'], "idx_offline_bundles_session");
            $table->unique(['bundle_hash'], "uq_offline_bundles_hash");
            $table->unique(['public_id'], "uq_offline_bundles_public_id");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
