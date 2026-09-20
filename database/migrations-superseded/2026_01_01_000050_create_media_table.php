<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media — created EARLY on purpose (migration order 000050).
 *
 * Several tables reference media in foreign keys: hall_schema_versions
 * (background floor plan), schema_objects (images), events (poster/cover),
 * ticket_templates (background), data_requests (export archive).
 *
 * If media were created late, every one of those foreign keys would have to be
 * added in a follow-up ALTER migration — fragile and easy to get wrong. Creating
 * the table first keeps the schema declarative.
 *
 * Storage model: the row describes the file, the bytes live on a disk. `disk`
 * plus `path` is the locator, so switching from local storage to S3 / Yandex
 * Object Storage / MinIO (ТЗ §3) is a config change, not a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();

            $table->string('disk', 32)->default('local')->comment('local | s3 | yandex | minio');
            $table->string('path', 512)->comment('Object key / relative path on the disk');
            $table->string('filename', 255);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('checksum', 64)->nullable()->comment('SHA-256, used to deduplicate uploads');
            $table->string('alt', 512)->nullable()->comment('Accessibility text');
            $table->string('title', 255)->nullable();

            // Generated renditions: {"thumb": "path", "large": "path"}
            $table->json('variants')->nullable();

            $table->string('mediable_type', 96)->nullable()->comment('Optional polymorphic owner');
            $table->unsignedBigInteger('mediable_id')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'mime_type']);
            $table->index(['mediable_type', 'mediable_id']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
