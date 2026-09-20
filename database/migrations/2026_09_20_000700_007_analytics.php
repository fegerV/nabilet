<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: analytics_events, ab_experiments, ab_variants, ab_assignments, ab_metrics, heatmap_events, embed_domains
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - analytics_events
 * - ab_experiments
 * - ab_variants
 * - ab_assignments
 * - ab_metrics
 * - heatmap_events
 * - embed_domains
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("analytics_events", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('anonymous_id', 36)->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('event_id')->nullable();
            $table->string('event_name', 100);
            $table->string('page_url', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('device', 64)->nullable();
            $table->string('browser', 128)->nullable();
            $table->string('country', 100)->nullable();
            $table->json('properties_json')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6);
            $table->index(['event_id', 'occurred_at'], "idx_analytics_event_time");
            $table->index(['event_name', 'occurred_at'], "idx_analytics_name_time");
            $table->index(['user_id', 'occurred_at'], "idx_analytics_user_time");
        });

        Schema::create("ab_experiments", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name', 255);
            $table->string('status', 32);
            $table->dateTime('starts_at', 6)->nullable();
            $table->dateTime('ends_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_ab_experiments_public_id");
        });

        Schema::create("ab_variants", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('experiment_id');
            $table->string('name', 255);
            $table->decimal('allocation_percent', 5, 2);
            $table->json('payload_json')->nullable();
            $table->index(['experiment_id'], "idx_ab_variants_experiment");
        });

        Schema::create("ab_assignments", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('experiment_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('anonymous_id', 36)->nullable();
            $table->dateTime('assigned_at', 6);
            $table->unique(['experiment_id', 'anonymous_id'], "uq_ab_assignment_anon");
            $table->unique(['experiment_id', 'user_id'], "uq_ab_assignment_user");
        });

        Schema::create("ab_metrics", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('experiment_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('metric_name', 100);
            $table->decimal('metric_value', 20, 6);
            $table->dateTime('occurred_at', 6);
            $table->index(['variant_id', 'occurred_at'], "idx_ab_metrics_variant_time");
        });

        Schema::create("heatmap_events", function (Blueprint $table): void {
            $table->id();
            $table->char('anonymous_id', 36)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('page_url', 2048);
            $table->string('event_type', 32);
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->integer('viewport_width')->nullable();
            $table->integer('viewport_height')->nullable();
            $table->decimal('scroll_percent', 5, 2)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->json('metadata_json')->nullable();
            $table->index(['page_url', 'occurred_at'], "idx_heatmap_page_time");
        });

        Schema::create("embed_domains", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('domain', 255);
            $table->boolean('active')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['organization_id', 'domain'], "uq_embed_domains_org_domain");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
