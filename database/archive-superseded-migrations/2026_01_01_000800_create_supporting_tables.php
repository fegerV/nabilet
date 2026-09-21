<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables: notifications, webhooks, transactional outbox, CMS/SEO,
 * analytics, AI, API access, security and platform operations
 * (ТЗ §35–§39, §43–§47, §52–§58, §68–§76, §87–§88).
 *
 * ── The transactional outbox ────────────────────────────────────────────────
 *
 * `outbox_messages` implements the outbox pattern for every side effect that must
 * not be lost: webhooks to n8n, Telegram notifications, email, PDF generation.
 *
 * The problem it solves: if a job is dispatched to Redis inside a transaction and
 * the transaction then rolls back, the job runs for an order that does not exist.
 * Conversely, if the job is dispatched after commit and the process dies in
 * between, the notification is silently lost.
 *
 * With the outbox, the intent is written to the SAME database transaction as the
 * state change. A separate worker then reads unprocessed rows and delivers them.
 * Either both the state change and the intent are committed, or neither is.
 *
 * Combined with `webhook_deliveries` (delivery attempts, retries, response logs),
 * this gives at-least-once delivery with visible delivery history (ТЗ §47).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Notification templates (ТЗ §43, §88) ─────────────────────────────
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete()
                ->comment('NULL = shipped default template');
            $table->string('key', 96)
                ->comment('order_created | payment_success | payment_failed | ticket_issued | event_reminder | refund | password_reset | email_verification');
            $table->enum('channel', ['email', 'telegram', 'web_push', 'sms'])->default('email');
            $table->string('locale', 8)->default('ru');

            $table->string('subject', 512)->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            /** Declared variables, e.g. ["user.name", "event.title", "ticket.qr"] */
            $table->json('variables')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'key', 'channel', 'locale'], 'notification_template_unique');
        });

        // ── Notification log (ТЗ §87, §88) ───────────────────────────────────
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();

            $table->string('template_key', 96);
            $table->enum('channel', ['email', 'telegram', 'web_push', 'sms'])->default('email');
            $table->string('recipient', 255);

            $table->enum('status', ['queued', 'sent', 'failed', 'skipped'])->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 512)->nullable();

            $table->json('payload')->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['channel', 'status']);
        });

        // ── Outgoing webhooks (ТЗ §46, §47) ──────────────────────────────────
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191)->nullable();
            $table->string('url', 1024);

            /** Subscribed events: ["order.paid", "ticket.issued"] or ["*"] */
            $table->json('events');

            $table->string('secret', 191)->comment('HMAC-SHA256 signing secret');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->json('headers')->nullable()->comment('Extra static headers, e.g. auth for n8n');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();

            $table->string('event', 96);
            $table->json('payload');

            $table->enum('status', ['pending', 'succeeded', 'failed', 'abandoned'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error', 512)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->dateTime('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Exponential backoff sweep: find deliveries that are due for a retry.
            $table->index(['status', 'next_retry_at'], 'webhook_delivery_retry_idx');
            $table->index(['organization_id', 'event', 'created_at']);
            $table->index('webhook_id');
        });

        // ── Transactional outbox ─────────────────────────────────────────────
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();

            $table->string('event', 96)->comment('Domain event name, e.g. order.paid');
            $table->json('payload');
            $table->string('aggregate_type', 96)->nullable();
            $table->string('aggregate_id', 64)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('available_at')->useCurrent()->comment('Delay/backoff gate');
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'available_at'], 'outbox_pending_idx');
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        // ── CMS: pages (ТЗ §71 "Контент") ────────────────────────────────────
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('slug', 191);
            $table->longText('body')->nullable();
            $table->string('locale', 8)->default('ru');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 512)->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('robots', 64)->nullable();

            $table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug', 'locale'], 'pages_slug_unique');
        });

        // ── Redirect manager (ТЗ §37) ────────────────────────────────────────
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('from_path', 1024);
            $table->string('to_path', 1024);
            $table->unsignedSmallInteger('status_code')->default(301)
                ->comment('301 | 302 | 307 | 308');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'from_path'], 'redirect_from_unique');
            $table->index(['organization_id', 'is_active']);
        });

        // ── SEO meta for any entity (ТЗ §35) ─────────────────────────────────
        // Polymorphic rather than a column set on every table: SEO fields get added
        // to new entity types without another migration.
        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('entity_type', 96);
            $table->string('entity_id', 64);
            $table->string('locale', 8)->default('ru');

            $table->string('title', 255)->nullable();
            $table->string('description', 512)->nullable();
            $table->string('h1', 255)->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('og_title', 255)->nullable();
            $table->string('og_description', 512)->nullable();
            $table->foreignId('og_image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('robots', 64)->nullable();
            $table->boolean('no_index')->default(false);
            $table->json('schema_org')->nullable()->comment('Generated JSON-LD');
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'locale'], 'seo_entity_locale_unique');
            $table->index('organization_id');
        });

        // ── UI translations (ТЗ §39) ─────────────────────────────────────────
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('locale', 8);
            $table->string('group', 64)->default('*');
            $table->string('key', 191);
            $table->text('value');
            $table->boolean('is_custom')->default(false)->comment('True when edited through the admin UI');
            $table->timestamps();

            $table->unique(['organization_id', 'locale', 'group', 'key'], 'translations_unique_key');
            $table->index(['locale', 'group']);
        });

        // ── Analytics (ТЗ §52, §89) ──────────────────────────────────────────
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 64)
                ->comment('page_view | event_view | seatmap_open | seat_selected | cart_created | checkout_started | payment_started | payment_success | ticket_downloaded');

            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('visitor_id', 64)->nullable()->index();
            $table->string('session_id', 64)->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('url', 1024)->nullable();
            $table->string('referrer', 512)->nullable();

            $table->json('properties')->nullable();
            $table->string('ip_hash', 64)->nullable()->comment('Hashed for GDPR; the raw IP is not stored');
            $table->timestamp('occurred_at')->useCurrent();

            // The funnel query: count by name over a date range.
            $table->index(['organization_id', 'name', 'occurred_at'], 'analytics_funnel_idx');
            $table->index(['event_session_id', 'name']);
        });

        // ── A/B testing (ТЗ §54) ─────────────────────────────────────────────
        Schema::create('ab_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('key', 96)->comment('Referenced from code/views to fetch the assigned variant');
            $table->enum('status', ['draft', 'running', 'paused', 'finished'])->default('draft');
            $table->string('target_metric', 64)->default('payment_success');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('ab_test_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ab_test_id')->constrained('ab_tests')->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('label', 191)->nullable();
            $table->unsignedTinyInteger('weight')->default(50)->comment('Traffic share in percent');

            // Denormalised counters, incremented by the analytics pipeline.
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('checkouts')->default(0);
            $table->unsignedBigInteger('payments')->default(0);
            $table->bigInteger('revenue_minor')->default(0);
            $table->timestamps();

            $table->unique(['ab_test_id', 'key']);
        });

        Schema::create('ab_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ab_test_id')->constrained('ab_tests')->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('variant_key', 32);
            $table->timestamp('assigned_at')->useCurrent();

            // A visitor must stay in the same variant for the whole experiment.
            $table->unique(['ab_test_id', 'visitor_id'], 'ab_assignment_unique');
        });

        // ── Heatmaps (ТЗ §55) ────────────────────────────────────────────────
        Schema::create('heatmap_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('page_url', 1024);
            $table->enum('type', ['click', 'scroll', 'move', 'rage_click', 'session'])->default('click');

            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->unsignedSmallInteger('scroll_depth')->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();

            $table->string('visitor_id', 64)->nullable();
            $table->string('element_selector', 512)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['organization_id', 'page_url', 'type'], 'heatmap_lookup_idx');
        });

        // ── AI providers & requests (ТЗ §69, §70) ────────────────────────────
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('provider', 32)->comment('openai | yandex | local | custom');
            $table->string('name', 191)->nullable();
            $table->text('api_key')->nullable()->comment('Encrypted at rest via the application key');
            $table->string('base_url', 512)->nullable();
            $table->string('model', 96)->nullable();
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->nullable();
            $table->unsignedInteger('monthly_request_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('purpose', 64)->comment('content | seo | chatbot | admin_assistant | search');
            $table->longText('prompt')->nullable();
            $table->longText('response')->nullable();

            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->bigInteger('cost_minor')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->string('error', 512)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'purpose', 'created_at']);
        });

        // ── API access (ТЗ §76) ──────────────────────────────────────────────
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('key_hash', 191)->comment('Only the hash is stored; the raw key is shown once');
            $table->string('prefix', 16)->index();
            $table->json('scopes')->nullable()->comment('e.g. ["events.read", "orders.read"]');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('api_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 512);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['api_key_id', 'created_at']);
        });

        // ── Security: IP rules (ТЗ §57) ──────────────────────────────────────
        Schema::create('ip_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->enum('list_type', ['blacklist', 'whitelist']);
            $table->enum('match_type', ['ip', 'cidr', 'country'])->default('ip');
            $table->string('value', 64)->comment('Single IP, CIDR block, or ISO country code');
            $table->json('applies_to')->nullable()->comment('["login","checkout","api","admin"]');
            $table->boolean('is_active')->default(true);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'list_type', 'is_active'], 'ip_rules_lookup_idx');
        });

        // ── Privacy / GDPR (ТЗ §56) ──────────────────────────────────────────
        Schema::create('privacy_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_id', 64)->nullable()->index();

            /**
             * Purpose-level consent, because "accept all" is not consent:
             * {"necessary": true, "analytics": false, "marketing": false}
             */
            $table->json('purposes');
            $table->string('policy_version', 32);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['organization_id', 'granted_at']);
        });

        Schema::create('data_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 191);
            $table->enum('type', ['export', 'erase'])->comment('GDPR right of access / right to be forgotten');
            $table->enum('status', ['pending', 'processing', 'completed', 'rejected'])->default('pending');
            $table->foreignId('result_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('rejection_reason', 512)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // ── Backups (ТЗ §72) ─────────────────────────────────────────────────
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('filename', 255);
            $table->string('disk', 32)->default('local');
            $table->string('path', 512);
            $table->unsignedBigInteger('size_bytes')->default(0);

            /** {"database": true, "settings": true, "hall_schemas": true, "media": false} */
            $table->json('includes');

            $table->enum('type', ['manual', 'daily', 'weekly', 'monthly'])->default('manual');
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->string('error', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Retention policy 7 daily / 4 weekly / 3 monthly (ТЗ §72)
            $table->index(['type', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::dropIfExists('data_requests');
        Schema::dropIfExists('privacy_consents');
        Schema::dropIfExists('ip_rules');
        Schema::dropIfExists('api_logs');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_providers');
        Schema::dropIfExists('heatmap_events');
        Schema::dropIfExists('ab_assignments');
        Schema::dropIfExists('ab_test_variants');
        Schema::dropIfExists('ab_tests');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
    }
};
