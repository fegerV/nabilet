<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events and sessions (ТЗ §11, §12, §13).
 *
 * NAMING DECISION: the occurrence table is `event_sessions`, not `sessions`.
 * Laravel's session driver uses `sessions` by default, and a name collision
 * between "the performance of a show" and "an HTTP session" is the kind of
 * ambiguity that produces a production incident at 3am. The domain model keeps the
 * name `Session`; only the table is disambiguated.
 *
 * THE KEY LINK: `event_sessions.hall_schema_version_id` — a session points at an
 * immutable VERSION, never at the mutable hall schema. This single column is what
 * makes historical tickets safe (ТЗ §14).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Event categories ─────────────────────────────────────────────────
        Schema::create('event_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('event_categories')->nullOnDelete();
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        // ── Events (ТЗ §12) ──────────────────────────────────────────────────
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('event_categories')->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete()
                ->comment('Default venue; individual sessions may override it');

            $table->string('title', 255);
            $table->string('slug', 191);
            $table->string('short_description', 512)->nullable();
            $table->longText('description')->nullable();

            $table->foreignId('poster_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->unsignedTinyInteger('age_limit')->nullable()->comment('0+, 6+, 12+, 16+, 18+');
            $table->unsignedInteger('duration_minutes')->nullable();

            $table->enum('status', [
                'draft', 'scheduled', 'published', 'completed', 'canceled', 'archived',
            ])->default('draft');
            $table->timestamp('published_at')->nullable();

            // SEO (ТЗ §35)
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 512)->nullable();
            $table->string('seo_keywords', 512)->nullable();
            $table->string('schema_type', 64)->default('Event')
                ->comment('Schema.org type: Event | TheaterEvent | MusicEvent | ...');

            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            // Catalog listing: published events, newest first
            $table->index(['organization_id', 'status', 'published_at'], 'events_catalog_idx');
            $table->index(['organization_id', 'category_id']);
        });

        // ── Event translations (ТЗ §39) ──────────────────────────────────────
        Schema::create('event_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('title', 255);
            $table->string('short_description', 512)->nullable();
            $table->longText('description')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 512)->nullable();
            $table->string('seo_keywords', 512)->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'locale']);
        });

        // ── Event media gallery ──────────────────────────────────────────────
        Schema::create('event_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->enum('role', ['gallery', 'poster', 'cover', 'attachment'])->default('gallery');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'media_id', 'role']);
        });

        // ── Sessions — one dated performance (ТЗ §13) ────────────────────────
        Schema::create('event_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('hall_id')->constrained('halls')->restrictOnDelete();

            // THE immutable link (ТЗ §14). Restrict, never cascade: a session must
            // not lose its geometry.
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->restrictOnDelete()
                ->comment('Immutable geometry version this session was built on');

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('timezone', 64)->default('Europe/Moscow')
                ->comment('Stored explicitly: DST and multi-region events make server-local time unsafe');

            $table->enum('status', [
                'draft', 'scheduled', 'on_sale', 'sold_out', 'finished', 'canceled',
            ])->default('draft');

            // Time-based sales gate, independent of the operational status switch
            $table->dateTime('sales_start')->nullable();
            $table->dateTime('sales_end')->nullable();

            // Denormalised counters for fast catalog rendering. Inventory is the
            // source of truth (ТЗ §59: availability must never be cached).
            $table->unsignedInteger('capacity_total')->default(0);
            $table->unsignedInteger('capacity_sold')->default(0);
            $table->unsignedInteger('capacity_held')->default(0);

            $table->boolean('inventory_materialized')->default(false)
                ->comment('True once inventory_items have been generated from the schema version');
            $table->timestamp('inventory_materialized_at')->nullable();

            $table->string('age_limit_override', 8)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate showings of the same event in the same hall at the
            // same instant — a real data-entry mistake that would otherwise produce
            // two overlapping inventories.
            $table->unique(['hall_id', 'starts_at'], 'session_hall_time_unique');
            $table->index(['event_id', 'starts_at']);
            $table->index(['organization_id', 'status', 'starts_at'], 'sessions_catalog_idx');
            $table->index(['organization_id', 'sales_start', 'sales_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sessions');
        Schema::dropIfExists('event_media');
        Schema::dropIfExists('event_translations');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_categories');
    }
};
