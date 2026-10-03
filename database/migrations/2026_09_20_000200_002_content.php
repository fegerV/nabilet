<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: event_categories, events, event_translations, venue_translations, page_translations, pages, seo_meta, redirects, media_assets, media_links
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - event_categories
 * - events
 * - event_translations
 * - venue_translations
 * - page_translations
 * - pages
 * - seo_meta
 * - redirects
 * - media_assets
 * - media_links
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("event_categories", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->string('status', 32);
            $table->unique(['public_id'], "uq_event_categories_public_id");
            $table->unique(['slug'], "uq_event_categories_slug");
        });

        Schema::create("events", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title', 500);
            $table->string('slug', 255);
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('poster', 2048)->nullable();
            $table->string('cover', 2048)->nullable();
            $table->string('age_limit', 32)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status', 32);
            $table->dateTime('published_at', 6)->nullable();
            $table->string('seo_title', 500)->nullable();
            $table->string('seo_description', 1000)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('robots', 255)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['category_id'], "idx_events_category");
            $table->index(['organization_id', 'status'], "idx_events_org_status");
            $table->index(['published_at'], "idx_events_published");
            $table->unique(['organization_id', 'slug'], "uq_events_org_slug");
            $table->unique(['public_id'], "uq_events_public_id");
        });

        Schema::create("event_translations", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('locale', 10);
            $table->string('title', 500)->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('seo_title', 500)->nullable();
            $table->string('seo_description', 1000)->nullable();
            $table->unique(['event_id', 'locale'], "uq_event_translations");
        });

        Schema::create("venue_translations", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('venue_id');
            $table->string('locale', 10);
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('seo_title', 500)->nullable();
            $table->string('seo_description', 1000)->nullable();
            $table->unique(['venue_id', 'locale'], "uq_venue_translations");
        });

        Schema::create("page_translations", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('page_id');
            $table->string('locale', 10);
            $table->string('title', 500)->nullable();
            $table->longText('content')->nullable();
            $table->string('slug', 255)->nullable();
            $table->string('seo_title', 500)->nullable();
            $table->string('seo_description', 1000)->nullable();
            $table->index(['locale', 'slug'], "idx_page_translations_locale_slug");
            $table->unique(['page_id', 'locale'], "uq_page_translations");
        });

        Schema::create("pages", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('title', 500);
            $table->string('slug', 255);
            $table->longText('content')->nullable();
            $table->string('status', 32);
            $table->dateTime('published_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['organization_id', 'slug'], "uq_pages_org_slug");
            $table->unique(['public_id'], "uq_pages_public_id");
        });

        Schema::create("seo_meta", function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('locale', 10);
            $table->string('title', 500)->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('robots', 255)->nullable();
            $table->string('og_title', 500)->nullable();
            $table->string('og_description', 1000)->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->json('schema_json')->nullable();
            $table->unique(['entity_type', 'entity_id', 'locale'], "uq_seo_entity_locale");
        });

        Schema::create("redirects", function (Blueprint $table): void {
            $table->id();
            $table->string('source', 2048);
            $table->string('destination', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('active')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['active'], "idx_redirect_active");
            $table->unique(['source'], "uq_redirect_source");
        });

        Schema::create("media_assets", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('filename', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->json('variants_json')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['checksum'], "idx_media_assets_checksum");
            $table->index(['organization_id'], "idx_media_assets_org");
            $table->unique(['public_id'], "uq_media_assets_public_id");
        });

        Schema::create("media_links", function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_asset_id');
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('role', 64);
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('created_at', 6);
            $table->index(['media_asset_id'], "idx_media_links_asset");
            $table->index(['entity_type', 'entity_id'], "idx_media_links_entity");
            $table->unique(['entity_type', 'entity_id', 'media_asset_id', 'role'], "uq_media_links");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
