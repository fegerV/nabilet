<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes, carts and orders (ТЗ §25, §26, §86).
 *
 * MONEY DISCIPLINE, applied consistently across all three tables:
 * every monetary column is `bigInteger` in MINOR units plus a `char(3)` currency.
 * No decimals, no floats. The reason is in the order total: it is the sum of
 * ticket prices, a percentage discount and a service fee — three roundings. Storing
 * minor units makes the arithmetic exact and lets the total be reconciled
 * kopeck-for-kopeck against the payment provider's report.
 *
 * The order row also stores the SNAPSHOT of what was agreed (email, phone, name,
 * prices) rather than only foreign keys. An order is a contract; if the customer
 * later changes their phone number in their profile, the historical order must
 * still show what was actually agreed at the time of purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Promo codes (ТЗ §86) ─────────────────────────────────────────────
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('title', 191)->nullable();

            $table->enum('type', ['fixed', 'percent']);
            $table->bigInteger('value')->comment('Minor units for "fixed", basis points-ish percent for "percent"');
            $table->char('currency', 3)->nullable()->comment('Required for type=fixed');

            // Applicability
            $table->enum('applies_to', ['all', 'event', 'category', 'first_purchase'])->default('all');
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('event_categories')->cascadeOnDelete();

            // Limits
            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_uses')->nullable()->comment('Total redemptions across all customers');
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->bigInteger('max_discount_minor')->nullable()->comment('Cap for percent codes');

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'starts_at', 'ends_at'], 'promo_active_idx');
        });

        // ── Carts (ТЗ §25) ───────────────────────────────────────────────────
        // A cart is scoped to ONE session: mixing seats from different performances
        // in one order would make hold expiry and partial refunds ambiguous.
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token', 64)->nullable()->index()
                ->comment('Guest checkout (ТЗ §83) — cart is identified by a signed cookie token');

            $table->foreignId('event_session_id')->constrained('event_sessions')->restrictOnDelete();
            $table->char('currency', 3)->default('RUB');

            $table->enum('status', ['active', 'converted', 'abandoned', 'expired'])->default('active');

            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();

            // Totals are recomputed on every change; stored so the checkout page and
            // abandoned-cart analytics do not have to re-derive them.
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);

            $table->dateTime('expires_at')->nullable()->comment('Aligned with the longest hold in the cart');
            $table->foreignId('converted_order_id')->nullable()
                ->comment('FK added in the deferred-constraints migration');
            $table->timestamp('converted_at')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at'], 'carts_sweep_idx');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->bigInteger('unit_price_minor');
            $table->char('currency', 3)->default('RUB');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('total_minor');
            $table->timestamps();

            // A seat cannot appear twice in one cart.
            $table->unique(['cart_id', 'inventory_item_id']);
        });

        // ── Orders (ТЗ §26) ──────────────────────────────────────────────────
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('order_number', 32)
                ->comment('Human-facing number, e.g. NB-2026-000123; unique per organization');

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('NULL for a guest order before the account is claimed (ТЗ §83)');

            // Contact SNAPSHOT — the contract as agreed at purchase time.
            $table->string('email', 191);
            $table->string('phone', 32)->nullable();
            $table->string('first_name', 96)->nullable();
            $table->string('last_name', 96)->nullable();

            // Money
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0)
                ->comment('Cumulative; the guard for partially_refunded -> refunded reads this');
            $table->char('currency', 3)->default('RUB');

            $table->enum('status', [
                'pending', 'awaiting_payment', 'paid', 'partially_refunded',
                'refunded', 'canceled', 'expired', 'failed',
            ])->default('pending');
            $table->enum('payment_status', [
                'unpaid', 'pending', 'paid', 'partially_refunded', 'refunded', 'failed',
            ])->default('unpaid')
                ->comment('Tracked separately for reconciliation with the provider');

            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->string('promo_code_snapshot', 64)->nullable();

            // Attribution (ТЗ §89: sales by channel)
            $table->enum('channel', [
                'direct', 'organic', 'telegram', 'vk', 'embed', 'qr', 'referral', 'email',
            ])->default('direct');
            $table->json('utm')->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('locale', 8)->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->dateTime('expires_at')->nullable()
                ->comment('Payment deadline; drives the order -> expired transition (ТЗ §84)');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            $table->string('customer_note', 1024)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'status', 'created_at'], 'orders_admin_list_idx');
            $table->index(['organization_id', 'payment_status']);
            $table->index(['user_id', 'status']);
            $table->index('email');
            $table->index(['status', 'expires_at'], 'orders_expiry_idx');
            $table->index(['organization_id', 'channel']);
        });

        // ── Order items ──────────────────────────────────────────────────────
        // One row per sold inventory item. The ticket link lives on the other side
        // (`tickets.order_item_id` is UNIQUE), which is what makes issuance
        // idempotent without duplicating the reference here.
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('event_session_id')->constrained('event_sessions')->restrictOnDelete();

            $table->string('description', 512)->nullable()->comment('Snapshot: "Сектор A, ряд 12, место 18"');

            $table->bigInteger('unit_price_minor');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('refunded_minor')->default(0);
            $table->char('currency', 3)->default('RUB');

            $table->enum('status', [
                'reserved', 'paid', 'issued', 'refunded', 'canceled',
            ])->default('reserved');

            $table->timestamps();

            // One inventory item can appear at most once in a given order.
            $table->unique(['order_id', 'inventory_item_id']);
            $table->index('inventory_item_id');
            $table->index('event_session_id');
        });

        // ── Promo usages ─────────────────────────────────────────────────────
        Schema::create('promo_code_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 191)->nullable();
            $table->bigInteger('discount_minor');
            $table->char('currency', 3)->default('RUB');
            $table->timestamp('used_at')->useCurrent();

            // Idempotency: one redemption per order, enforced by the database.
            $table->unique(['promo_code_id', 'order_id']);
            $table->index(['organization_id', 'used_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('promo_codes');
    }
};
