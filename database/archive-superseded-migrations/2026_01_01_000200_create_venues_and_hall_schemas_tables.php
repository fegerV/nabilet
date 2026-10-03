<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venues, halls and versioned hall schemas (ТЗ §11, §14, §90).
 *
 * THE CENTRAL RULE OF THIS SCHEMA:
 *
 *     hall_schemas          — the logical hall (mutable container)
 *       └── hall_schema_versions  — immutable once published
 *             ├── sectors
 *             ├── rows
 *             ├── seats
 *             ├── tables
 *             ├── standing_zones
 *             └── schema_objects (stage, entrances, labels, images)
 *
 * A session references a hall_schema_VERSION, never "the current schema of the
 * hall". That is what protects historical orders: once version 1 is published and
 * sold, editing the hall creates version 2, and the tickets sold against version 1
 * keep pointing at the exact geometry the customer saw.
 *
 * Enforced in two places, deliberately redundant:
 *   1. `hall_schema_versions.is_immutable` — a flag the application checks.
 *   2. The model layer refuses any write to a child row of a published version.
 * A single check would be enough until someone writes a raw query; the flag makes
 * the intent visible in the data itself.
 *
 * GEOMETRY: every drawable object carries x, y, width, height, rotation. Storing
 * geometry (rather than deriving it) is what allows curved amphitheatre rows, a
 * rotated stage and arbitrary free-form layouts without special cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Venues (ТЗ §11) ──────────────────────────────────────────────────
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->string('address', 512)->nullable();
            $table->string('city', 96)->nullable();
            $table->string('region', 96)->nullable();
            $table->string('country', 2)->default('RU');
            $table->string('postal_code', 16)->nullable();
            $table->string('timezone', 64)->default('Europe/Moscow');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('metro', 191)->nullable();
            $table->text('description')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 255)->nullable();

            // SEO (ТЗ §35)
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 512)->nullable();

            $table->enum('status', ['active', 'hidden', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
            $table->index('city');
        });

        // ── Halls — the physical room ────────────────────────────────────────
        Schema::create('halls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('code', 32)->nullable();
            $table->string('floor', 32)->nullable();
            $table->unsignedInteger('default_capacity')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['venue_id', 'name']);
            $table->index(['organization_id', 'venue_id']);
        });

        // ── Hall schema — logical container, mutable ─────────────────────────
        Schema::create('hall_schemas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('hall_id')->constrained('halls')->restrictOnDelete();
            $table->string('name', 191)->default('Основная схема');
            $table->unsignedInteger('current_version_number')->nullable()
                ->comment('Latest version; NOT what sessions point at');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hall_id', 'name']);
            $table->index(['organization_id', 'status']);
        });

        // ── Immutable schema versions (ТЗ §14) ───────────────────────────────
        Schema::create('hall_schema_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('hall_schema_id')->constrained('hall_schemas')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');

            $table->boolean('is_immutable')->default(false)
                ->comment('Set true on publish. Any UPDATE to this version or its children is refused.');

            // Canvas / coordinate system
            $table->unsignedInteger('canvas_width')->default(2000);
            $table->unsignedInteger('canvas_height')->default(1500);
            $table->string('unit', 16)->default('px')->comment('px | mm | m');
            $table->unsignedSmallInteger('grid_size')->default(10);
            $table->boolean('snap_to_grid')->default(true);

            // Background floor plan (ТЗ §17)
            $table->foreignId('background_media_id')->nullable()
                ->comment('Floor plan image: PNG/JPG/WEBP/SVG')
                ->constrained('media')->nullOnDelete();
            $table->decimal('background_opacity', 4, 2)->default(0.50);
            $table->boolean('background_locked')->default(true);

            $table->unsignedInteger('capacity_total')->default(0)
                ->comment('Denormalised for fast catalog display; recomputed on publish');

            $table->json('meta')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hall_schema_id', 'version_number'], 'hsv_schema_version_unique');
            $table->index(['organization_id', 'status']);
        });

        // ── Sectors (ТЗ §18) ─────────────────────────────────────────────────
        Schema::create('sectors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('code', 32);
            $table->enum('type', ['seated', 'standing', 'mixed'])->default('seated');
            $table->unsignedInteger('capacity')->default(0)
                ->comment('For standing sectors this is the sellable headcount (ТЗ §21)');
            $table->string('color', 16)->default('#3B82F6');
            $table->unsignedInteger('sort_order')->default(0);

            // Geometry — real numbers, not integers: rotated and scaled layouts
            // need sub-pixel precision to avoid drift when re-editing.
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->decimal('rotation', 7, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['hall_schema_version_id', 'code']);
            $table->index('hall_schema_version_id');
        });

        // ── Rows (ТЗ §19) ────────────────────────────────────────────────────
        Schema::create('rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('name', 64)->nullable();
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr')
                ->comment('Seat numbering direction, as printed on the ticket');
            $table->unsignedInteger('sort_order')->default(0);

            // TEMPLATE price. The authoritative per-session price lives on
            // inventory_items (ТЗ §22) — this is only the default used when
            // materialising a session.
            $table->bigInteger('base_price_minor')->nullable()->comment('Minor units (kopecks)');
            $table->char('currency', 3)->default('RUB');

            $table->timestamps();

            $table->unique(['sector_id', 'number']);
            $table->index('hall_schema_version_id');
        });

        // ── Seats (ТЗ §20) ───────────────────────────────────────────────────
        Schema::create('seats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->foreignId('row_id')->constrained('rows')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('label', 32)->nullable()->comment('Printed label, e.g. "12A" for aisles');

            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(20);
            $table->decimal('height', 10, 2)->default(20);
            $table->decimal('rotation', 7, 2)->default(0);

            $table->enum('type', ['standard', 'vip', 'wheelchair', 'companion', 'blocked', 'custom'])
                ->default('standard')
                ->comment('wheelchair/companion support accessible-seating rules (ТЗ §20)');

            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['row_id', 'number']);
            $table->index('hall_schema_version_id');
            $table->index(['sector_id', 'type']);
            // Spatial access for the editor's viewport culling (ТЗ §64)
            $table->index(['hall_schema_version_id', 'x', 'y'], 'seats_spatial_idx');
        });

        // ── Tables (banquet / cabaret layouts) ───────────────────────────────
        Schema::create('tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->cascadeOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->cascadeOnDelete();
            $table->string('name', 96);
            $table->string('code', 32)->nullable();
            $table->unsignedInteger('capacity')->default(0);
            $table->enum('shape', ['round', 'rect', 'oval', 'custom'])->default('round');

            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(80);
            $table->decimal('height', 10, 2)->default(80);
            $table->decimal('rotation', 7, 2)->default(0);

            $table->timestamps();

            $table->index('hall_schema_version_id');
        });

        // ── Standing zones (ТЗ §21) ──────────────────────────────────────────
        // No physical seats: the zone itself is the sellable pool of headcount.
        // Sold as slots in inventory_items, never as seat references.
        Schema::create('standing_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('code', 32);
            $table->unsignedInteger('capacity')->comment('ТЗ §21: capacity 1000 -> available 734');

            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->decimal('rotation', 7, 2)->default(0);

            $table->timestamps();

            $table->unique(['hall_schema_version_id', 'code']);
        });

        // ── Decorative / structural objects (ТЗ §15) ─────────────────────────
        // Stage, entrance, toilet, text, image, custom — everything the editor can
        // place that is not inventory. Kept in one table with a `type` discriminator
        // so adding a new editor tool is a data change, not a migration.
        Schema::create('schema_objects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hall_schema_version_id')->constrained('hall_schema_versions')->cascadeOnDelete();
            $table->enum('type', [
                'stage', 'entrance', 'exit', 'toilet', 'bar', 'wardrobe',
                'text', 'image', 'line', 'rect', 'icon', 'custom',
            ]);
            $table->string('label', 255)->nullable();
            $table->string('text_content', 512)->nullable();

            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->decimal('rotation', 7, 2)->default(0);

            $table->string('color', 16)->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->integer('z_index')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['hall_schema_version_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_objects');
        Schema::dropIfExists('standing_zones');
        Schema::dropIfExists('tables');
        Schema::dropIfExists('seats');
        Schema::dropIfExists('rows');
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('hall_schema_versions');
        Schema::dropIfExists('hall_schemas');
        Schema::dropIfExists('halls');
        Schema::dropIfExists('venues');
    }
};
