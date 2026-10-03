<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: notification_templates, notifications, consents, privacy_requests
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - notification_templates
 * - notifications
 * - consents
 * - privacy_requests
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("notification_templates", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->string('code', 100);
            $table->string('channel', 32);
            $table->string('locale', 10);
            $table->string('subject', 500)->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('active')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['code', 'channel', 'locale'], "uq_notification_template_code_channel_locale");
            $table->unique(['public_id'], "uq_notification_templates_public_id");
        });

        Schema::create("notifications", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('channel', 32);
            $table->string('type', 100);
            $table->string('recipient', 500)->nullable();
            $table->string('status', 32);
            $table->string('provider_message_id', 255)->nullable();
            $table->json('payload_json')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['status'], "idx_notifications_status");
            $table->index(['user_id', 'created_at'], "idx_notifications_user_time");
            $table->unique(['public_id'], "uq_notifications_public_id");
        });

        Schema::create("consents", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('anonymous_id', 36)->nullable();
            $table->string('consent_type', 64);
            $table->string('status', 32);
            $table->string('policy_version', 50)->nullable();
            $table->binary('ip_address', 16)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['anonymous_id', 'consent_type'], "idx_consents_anonymous_type");
            $table->index(['user_id', 'consent_type'], "idx_consents_user_type");
        });

        Schema::create("privacy_requests", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->json('payload_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->index(['user_id'], "idx_privacy_requests_user");
            $table->unique(['public_id'], "uq_privacy_requests_public_id");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
