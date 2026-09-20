<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: organizations, roles, permissions, role_permissions, users, user_organization, user_roles, user_sessions, login_logs
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - organizations
 * - roles
 * - permissions
 * - role_permissions
 * - users
 * - user_organization
 * - user_roles
 * - user_sessions
 * - login_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("organizations", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->string('logo', 2048)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('status', 32);
            $table->json('settings_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['status'], "idx_organizations_status");
            $table->unique(['public_id'], "uq_organizations_public_id");
            $table->unique(['slug'], "uq_organizations_slug");
        });

        Schema::create("roles", function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('description', 500)->nullable();
            $table->unique(['slug'], "uq_roles_slug");
        });

        Schema::create("permissions", function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->unique(['slug'], "uq_permissions_slug");
        });

        Schema::create("role_permissions", function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create("users", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->string('email', 255)->nullable();
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->dateTime('phone_verified_at', 6)->nullable();
            $table->string('status', 32);
            $table->string('locale', 10);
            $table->string('timezone', 64);
            $table->dateTime('last_login_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['phone'], "idx_users_phone");
            $table->index(['status'], "idx_users_status");
            $table->unique(['email'], "uq_users_email");
            $table->unique(['public_id'], "uq_users_public_id");
        });

        Schema::create("user_organization", function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('role_id');
            $table->dateTime('created_at', 6);
            $table->primary(['user_id', 'organization_id']);
            $table->index(['organization_id', 'role_id'], "idx_uo_org_role");
        });

        Schema::create("user_roles", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['organization_id', 'role_id'], "idx_user_roles_org_role");
            $table->index(['role_id'], "idx_user_roles_role");
            $table->unique(['user_id', 'organization_id', 'role_id'], "uq_user_roles");
        });

        Schema::create("user_sessions", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('session_token_hash', 64);
            $table->string('device_name', 255)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->binary('ip_address', 16)->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['expires_at'], "idx_user_sessions_expires");
            $table->index(['user_id'], "idx_user_sessions_user");
            $table->unique(['session_token_hash'], "uq_user_sessions_token");
        });

        Schema::create("login_logs", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('identifier', 255)->nullable();
            $table->boolean('success');
            $table->binary('ip_address', 16)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['identifier', 'created_at'], "idx_login_logs_identifier_time");
            $table->index(['user_id', 'created_at'], "idx_login_logs_user_time");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
