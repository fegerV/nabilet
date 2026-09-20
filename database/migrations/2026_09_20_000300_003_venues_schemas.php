<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables: venues, halls, hall_schema_versions, sectors, hall_rows, seats, hall_tables, standing_zones
 *
 * Generated from the verified production schema — see
 * nabilet_core_spec/migrations.sql, which is the source of truth. Money is integer
 * minor units; timestamps are DATETIME(6); every name matches the spec.
 *
 * - venues
 * - halls
 * - hall_schema_versions
 * - sectors
 * - hall_rows
 * - seats
 * - hall_tables
 * - standing_zones
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("venues", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('region', 150)->nullable();
            $table->string('city', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 32);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['organization_id', 'city'], "idx_venues_org_city");
            $table->unique(['organization_id', 'slug'], "uq_venues_org_slug");
            $table->unique(['public_id'], "uq_venues_public_id");
        });

        Schema::create("halls", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('venue_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 32);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_halls_public_id");
            $table->unique(['venue_id', 'name'], "uq_halls_venue_name");
        });

        Schema::create("hall_schema_versions", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('hall_id');
            $table->unsignedInteger('version');
            $table->string('status', 32);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('background_url', 2048)->nullable();
            $table->json('schema_json');
            $table->dateTime('published_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['hall_id', 'status'], "idx_schema_hall_status");
            $table->unique(['hall_id', 'version'], "uq_schema_hall_version");
            $table->unique(['public_id'], "uq_schema_public_id");
        });

        Schema::create("sectors", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('schema_version_id');
            $table->string('name', 255);
            $table->string('code', 100);
            $table->string('type', 32);
            $table->decimal('x', 12, 3)->default(0.000);
            $table->decimal('y', 12, 3)->default(0.000);
            $table->decimal('width', 12, 3)->nullable();
            $table->decimal('height', 12, 3)->nullable();
            $table->string('color', 32)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['schema_version_id', 'code'], "uq_sector_schema_code");
            $table->unique(['public_id'], "uq_sectors_public_id");
        });

        Schema::create("hall_rows", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('sector_id');
            $table->string('number', 50);
            $table->string('name', 100)->nullable();
            $table->bigInteger('price_amount')->default(0);
            $table->char('currency', 3);
            $table->decimal('x', 12, 3)->nullable();
            $table->decimal('y', 12, 3)->nullable();
            $table->decimal('rotation', 8, 3)->default(0.000);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_hall_rows_public_id");
            $table->unique(['sector_id', 'number'], "uq_hall_rows_sector_number");
        });

        Schema::create("seats", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('row_id');
            $table->string('number', 50);
            $table->string('label', 100)->nullable();
            $table->decimal('x', 12, 3)->default(0.000);
            $table->decimal('y', 12, 3)->default(0.000);
            $table->decimal('width', 12, 3)->nullable();
            $table->decimal('height', 12, 3)->nullable();
            $table->decimal('rotation', 8, 3)->default(0.000);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_seats_public_id");
            $table->unique(['row_id', 'number'], "uq_seats_row_number");
        });

        Schema::create("hall_tables", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('sector_id');
            $table->string('name', 100);
            $table->decimal('x', 12, 3)->default(0.000);
            $table->decimal('y', 12, 3)->default(0.000);
            $table->decimal('width', 12, 3);
            $table->decimal('height', 12, 3);
            $table->decimal('rotation', 8, 3)->default(0.000);
            $table->unsignedInteger('capacity')->nullable();
            $table->json('metadata_json')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_hall_tables_public_id");
        });

        Schema::create("standing_zones", function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('sector_id');
            $table->string('name', 255);
            $table->unsignedInteger('capacity');
            $table->bigInteger('price_amount')->default(0);
            $table->char('currency', 3);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['public_id'], "uq_standing_public_id");
            $table->unique(['sector_id', 'name'], "uq_standing_sector_name");
        });
    }

    public function down(): void
    {
        // Irreversible by design: dropping a table destroys sold tickets and payment
        // history. Roll forward with a new migration instead.
    }
};
