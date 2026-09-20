<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: webhooks, webhook_deliveries, webhook_events, api_keys, idempotency_keys, ip_rules, modules, settings, audit_logs
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - webhooks
 * - webhook_deliveries
 * - webhook_events
 * - api_keys
 * - idempotency_keys
 * - ip_rules
 * - modules
 * - settings
 * - audit_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("webhooks", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('url', 2048);
            $table->text('secret_encrypted');
            $table->json('events_json');
            $table->boolean('active')->default(1);
            $table->unsignedInteger('retry_limit')->default(10);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['organization_id', 'active'], "idx_webhooks_org_active");
            $table->unique(['public_id'], "uq_webhooks_public_id");
        });

        Schema::create("webhook_deliveries", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('webhook_id');
            $table->char('delivery_id', 36);
            $table->string('event_name', 100);
            $table->json('payload_json');
            $table->smallInteger('status_code')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->mediumText('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('next_retry_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['next_retry_at'], "idx_webhook_delivery_retry");
            $table->unique(['webhook_id', 'delivery_id'], "uq_webhook_delivery");
        });

        Schema::create("webhook_events", function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('provider_event_id', 255);
            $table->string('event_name', 100);
            $table->json('payload_json');
            $table->dateTime('processed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['processed_at'], "idx_webhook_events_processed");
            $table->unique(['provider', 'provider_event_id'], "uq_webhook_events_provider_id");
        });

        Schema::create("api_keys", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name', 255);
            $table->string('key_prefix', 20);
            $table->char('key_hash', 64);
            $table->json('scopes_json')->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['key_hash'], "uq_api_keys_hash");
            $table->unique(['public_id'], "uq_api_keys_public_id");
        });

        Schema::create("idempotency_keys", function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->smallInteger('response_status')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->dateTime('locked_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['expires_at'], "idx_idempotency_expire");
            $table->unique(['scope', 'key_hash'], "uq_idempotency_scope_hash");
        });

        Schema::create("ip_rules", function (Blueprint $table): void {
            $table->id();
            $table->binary('ip_address', 16)->nullable();
            $table->string('cidr', 64)->nullable();
            $table->string('rule_type', 16);
            $table->string('scope', 32);
            $table->boolean('active')->default(1);
            $table->string('reason', 500)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['expires_at'], "idx_ip_rules_expire");
            $table->index(['scope', 'active'], "idx_ip_rules_scope_active");
        });

        Schema::create("modules", function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('version', 50);
            $table->boolean('enabled')->default(1);
            $table->json('manifest_json');
            $table->dateTime('installed_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['name'], "uq_modules_name");
        });

        Schema::create("settings", function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('setting_key', 255);
            $table->json('value_json')->nullable();
            $table->boolean('encrypted')->default(0);
            $table->dateTime('updated_at', 6);
            $table->unique(['scope', 'setting_key'], "uq_settings_scope_key");
        });

        Schema::create("audit_logs", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('action', 150);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();
            $table->binary('ip_address', 16)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['entity_type', 'entity_id', 'created_at'], "idx_audit_entity_time");
            $table->index(['organization_id', 'created_at'], "idx_audit_org_time");
            $table->index(['user_id', 'created_at'], "idx_audit_user_time");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
