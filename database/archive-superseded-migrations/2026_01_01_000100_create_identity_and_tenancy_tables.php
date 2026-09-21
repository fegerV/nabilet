<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity, tenancy and platform-level tables.
 *
 * MULTI-TENANCY CONTRACT (see docs/ARCHITECTURE.md):
 * every business table carries `organization_id`. `users` and `roles` are the only
 * exceptions — they may be platform-level (organization_id NULL) to support the
 * Super Admin who provisions organizations. Everything else MUST be tenant-scoped
 * and the application layer enforces it fail-closed.
 *
 * ON DELETE STRATEGY, deliberately conservative:
 *   - RESTRICT for anything financially or legally meaningful (orders, payments,
 *     tickets, inventory). A cascade here would silently destroy sales history.
 *   - CASCADE only for pure child rows with no independent meaning (pivot tables,
 *     translations, cart items).
 * This is why several FKs below are declared explicitly rather than using
 * constrained() with a default cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Organizations (ТЗ §10) ───────────────────────────────────────────
        // Present from day one so one installation can serve several organizers
        // later without a data migration.
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('legal_name', 191)->nullable();
            $table->string('inn', 12)->nullable()->comment('ИНН для чеков и документов');
            $table->string('kpp', 9)->nullable();
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->char('currency', 3)->default('RUB');
            $table->string('locale', 8)->default('ru');
            $table->enum('status', ['active', 'suspended', 'archived'])->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ── Users ────────────────────────────────────────────────────────────
        // Email is unique PER ORGANIZATION, not globally: the same customer may
        // buy from two organizers on one installation.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()
                ->comment('NULL = platform-level operator (Super Admin)')
                ->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('email', 191);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 191)->nullable()->comment('NULL for OAuth-only accounts');
            $table->string('phone', 32)->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->enum('status', ['active', 'blocked', 'deleted'])->default('active');
            $table->boolean('is_guest')->default(false)
                ->comment('Guest checkout (ТЗ §83): account created implicitly, may be claimed later');

            // 2FA (ТЗ §8)
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Same email may exist in two organizations, but only once per org.
            $table->unique(['organization_id', 'email']);
            $table->index(['organization_id', 'status']);
            $table->index('email');
        });

        // ── Roles & permissions (ТЗ §9) ──────────────────────────────────────
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()
                ->comment('NULL = system role available to every organization')
                ->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('slug', 64);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)
                ->comment('System roles cannot be deleted or renamed');
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 96)->unique()->comment('e.g. orders.refund, tickets.checkin');
            $table->string('name', 191);
            $table->string('group', 64)->nullable()->comment('Grouping in the admin permission matrix');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'role_id']);
            $table->index('organization_id');
        });

        // ── Sessions & login history (ТЗ §8, §42) ────────────────────────────
        // Named user_sessions to avoid colliding with Laravel's `sessions` table.
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('session_id', 191)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device', 96)->nullable()->comment('e.g. Chrome / Windows');
            $table->string('platform', 32)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('login_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('email_attempted', 191)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason', 64)->nullable();
            $table->string('method', 32)->default('password')->comment('password | oauth:yandex | oauth:vk | telegram');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['email_attempted', 'created_at']);
        });

        // ── Audit log (ТЗ §75) ───────────────────────────────────────────────
        // Stores before/after values because the question asked of an audit log is
        // never "was it changed" but "from what to what, by whom".
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->comment('created | updated | deleted | restored');
            $table->string('entity_type', 96);
            $table->string('entity_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'audit_entity_idx');
            $table->index(['organization_id', 'created_at']);
        });

        // ── Settings (ТЗ §71) ────────────────────────────────────────────────
        // organization_id NULL = global platform setting.
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('group', 64);
            $table->string('key', 128);
            $table->json('value')->nullable();
            $table->boolean('is_public')->default(false)
                ->comment('Public settings are safe to expose to the frontend');
            $table->timestamps();

            $table->unique(['organization_id', 'group', 'key'], 'settings_unique_key');
        });

        // ── Module registry (ТЗ §5) ──────────────────────────────────────────
        // Mirrors the module.json manifests into the database so the admin UI can
        // toggle modules and store per-module settings.
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('title', 191);
            $table->string('version', 32);
            $table->enum('type', ['core', 'plugin'])->default('core');
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('login_logs');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');
    }
};
