<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, refunds, tickets and check-in (ТЗ §27, §28, §29, §30, §31, §32, §33).
 *
 * ── The idempotency architecture (ТЗ §28) ────────────────────────────────────
 *
 * The spec's requirement is precise: three identical `payment.succeeded` webhooks
 * must still produce ONE paid order and ONE set of issued tickets. That is
 * achieved by two database-level guarantees, not by careful coding:
 *
 *   1. `payment_transactions.provider_event_id` is UNIQUE.
 *      The webhook handler inserts a transaction row FIRST. If the insert violates
 *      the unique index, the event was already processed — return 200 and stop.
 *      This turns "have I seen this before?" into an atomic database operation
 *      rather than a read-then-write race.
 *
 *   2. `tickets.order_item_id` is UNIQUE.
 *      Ticket issuance is keyed on the order item, so re-running the issuance job
 *      after a retry cannot create a second ticket for the same seat.
 *
 * Both are enforced by the database because application-level "check then act" is
 * exactly the pattern that fails under concurrency.
 *
 * ── QR payload (ТЗ §30) ──────────────────────────────────────────────────────
 * `qr_token` is a random opaque string; `qr_payload` is the signed
 * "NB1.<id>.<token>.<signature>" string. No email, phone, price or password is
 * stored in or derived from the QR — a scanner is an untrusted client.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Idempotency keys (ТЗ §28, §76) ───────────────────────────────────
        // Generic guard usable by any state-changing endpoint, not just payments.
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('scope', 96)->comment('e.g. POST:/api/v1/orders');
            $table->string('key', 191)->comment('Client-supplied Idempotency-Key header');
            $table->string('request_hash', 64)->comment('Hash of the request payload');

            // The stored response, so a replay returns the original result rather
            // than re-executing the operation.
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();

            $table->string('resource_type', 96)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'key'], 'idempotency_scope_key_unique');
            $table->index('expires_at');
        });

        // ── Payments (ТЗ §27) ────────────────────────────────────────────────
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('provider', 32)->default('yookassa')
                ->comment('Provider-agnostic: the PaymentProvider abstraction maps onto this');
            $table->string('provider_payment_id', 191)->nullable();

            $table->enum('status', [
                'pending', 'waiting_for_capture', 'succeeded',
                'canceled', 'failed', 'partially_refunded', 'refunded',
            ])->default('pending');

            $table->bigInteger('amount_minor');
            $table->bigInteger('refunded_minor')->default(0);
            $table->char('currency', 3)->default('RUB');

            $table->string('method', 32)->nullable()->comment('bank_card | sbp | apple_pay | ...');
            $table->boolean('is_two_step')->default(false)
                ->comment('Authorize then capture; required for pre-orders and cancellations');

            $table->string('confirmation_url', 1024)->nullable();
            $table->string('description', 255)->nullable();

            // Raw provider payload, kept for dispute resolution and support.
            $table->json('payload')->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One provider payment id maps to exactly one local payment.
            $table->unique(['provider', 'provider_payment_id'], 'payment_provider_id_unique');
            $table->index(['order_id', 'status']);
            $table->index(['organization_id', 'status', 'created_at']);
        });

        // ── Payment transactions — the webhook ledger (ТЗ §28) ───────────────
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();

            $table->enum('type', [
                'create', 'capture', 'cancel', 'refund', 'webhook', 'status_check',
            ]);

            /**
             * IDEMPOTENCY GUARD.
             * Unique across ALL providers. The webhook handler inserts here first;
             * a duplicate-key violation means "already processed" and the handler
             * returns success without touching order or ticket state.
             */
            $table->string('provider_event_id', 191)->nullable()
                ->comment('Unique dedup key for webhook events');

            $table->string('status', 32)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();

            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_message', 512)->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique('provider_event_id', 'payment_tx_provider_event_unique');
            $table->index(['payment_id', 'type']);
            $table->index(['organization_id', 'created_at']);
        });

        // ── Refunds (ТЗ §85) ─────────────────────────────────────────────────
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('provider', 32)->default('yookassa');
            $table->string('provider_refund_id', 191)->nullable();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('RUB');

            $table->enum('status', ['requested', 'processing', 'succeeded', 'failed'])->default('requested');
            $table->enum('scope', ['full', 'partial'])->default('full');

            /** Which order items were returned: [{"order_item_id": 12, "amount_minor": 5000}] */
            $table->json('items')->nullable();

            $table->string('reason', 512)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_reason', 512)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_refund_id'], 'refund_provider_id_unique');
            $table->index(['order_id', 'status']);
            $table->index(['organization_id', 'status', 'created_at']);
        });

        // ── Ticket templates (ТЗ §65, §66) ───────────────────────────────────
        Schema::create('ticket_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);

            $table->enum('preset', ['a4', 'a5', '210x74', '148x105', 'mobile', 'custom'])->default('a4');
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            $table->foreignId('background_media_id')->nullable()->constrained('media')->nullOnDelete();

            /**
             * Designer layout (ТЗ §65): an array of positioned elements
             * (text, qr, logo, photo, line, rect, icon, ticket_data) with
             * dynamic variables such as {{event.title}} or {{seat.row}}.
             */
            $table->json('layout')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });

        // ── Tickets (ТЗ §29, §30) ────────────────────────────────────────────
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('ticket_number', 32)->comment('Human-facing, unique per organization');

            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();

            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('event_session_id')->constrained('event_sessions')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('seat_id')->nullable()->constrained('seats')->restrictOnDelete();

            $table->foreignId('ticket_template_id')->nullable()->constrained('ticket_templates')->nullOnDelete();

            $table->string('holder_name', 191)->nullable()
                ->comment('Optional: tickets may be transferred; the holder is not always the buyer');
            $table->string('holder_email', 191)->nullable();

            $table->enum('status', ['issued', 'used', 'refunded', 'canceled', 'revoked', 'expired'])
                ->default('issued');

            // QR (ТЗ §30) — opaque token + signature, no personal data
            $table->string('qr_token', 64)->unique();
            $table->string('qr_payload', 255)->comment('NB1.<ticketId>.<token>.<hmac>');
            $table->string('qr_key_id', 16)->default('k1')->comment('Allows key rotation without ambiguity');

            // Location snapshot for the PDF and the scanner screen
            $table->string('seat_label', 512)->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Set after the session ends');
            $table->foreignId('pdf_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            /**
             * IDEMPOTENCY GUARD for issuance.
             * One ticket per order item, enforced by the database — re-running the
             * issuance job cannot duplicate a ticket.
             */
            $table->unique('order_item_id', 'ticket_one_per_order_item');

            $table->unique(['organization_id', 'ticket_number']);
            $table->index(['event_session_id', 'status'], 'tickets_session_status_idx');
            $table->index(['organization_id', 'status']);
            $table->index('inventory_item_id');
        });

        // ── Check-in devices (ТЗ §31) ────────────────────────────────────────
        Schema::create('checkin_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('code', 32);
            $table->enum('platform', ['android', 'ios', 'web'])->default('android');
            $table->string('device_uuid', 191)->nullable();

            $table->string('api_key_hash', 191)->comment('Only the hash is stored; the raw key is shown once');
            $table->string('api_key_prefix', 12)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        // ── Ticket scans — full scan history (ТЗ §32, §74) ───────────────────
        // Rejected scans are recorded too: "why was this ticket refused at the door"
        // is one of the most common support questions and needs evidence.
        Schema::create('ticket_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete()
                ->comment('NULL when the token did not resolve to any ticket');
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
            $table->foreignId('checkin_device_id')->nullable()->constrained('checkin_devices')->nullOnDelete();

            $table->string('scanned_token', 255)->nullable();

            $table->enum('result', [
                'valid', 'already_used', 'invalid_signature', 'not_found',
                'wrong_session', 'revoked', 'refunded', 'expired', 'not_yet_valid',
            ]);

            $table->timestamp('scanned_at')->useCurrent();
            $table->boolean('from_offline')->default(false);
            $table->timestamp('offline_scanned_at')->nullable()
                ->comment('Device clock at scan time; used for reconciliation ordering');
            $table->timestamp('synced_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable();

            $table->index(['event_session_id', 'result']);
            $table->index(['organization_id', 'scanned_at']);
            $table->index(['checkin_device_id', 'scanned_at']);
            $table->index('ticket_id');
        });

        // ── Offline bundles (ТЗ §33) ─────────────────────────────────────────
        Schema::create('offline_bundles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('event_session_id')->constrained('event_sessions')->restrictOnDelete();
            $table->foreignId('checkin_device_id')->constrained('checkin_devices')->restrictOnDelete();

            $table->unsignedInteger('ticket_count')->default(0);
            $table->string('payload_hash', 64);
            $table->string('signature', 255);
            $table->string('storage_path', 512)->nullable();

            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['event_session_id', 'checkin_device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_bundles');
        Schema::dropIfExists('ticket_scans');
        Schema::dropIfExists('checkin_devices');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_templates');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('idempotency_keys');
    }
};
