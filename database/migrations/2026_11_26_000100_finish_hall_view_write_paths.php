<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Завершение перехода halls → venues (аудит 2026-11-25, P1 4.1).
 *
 * VIEW 2026_11_18_000800 потеряла четыре колонки старой таблицы halls
 * (вместимость, размеры, фото) и не допускает UPDATE name (COALESCE). Поэтому
 * `PUT /halls/{publicId}` падал 500, а `Hall::create()` — тем более. Переносим
 * эти поля на реальную таблицу venues и пересоздаём VIEW над ней: запись идёт
 * только в таблицу, читальники по-прежнему видят те же колонки halls.
 *
 * Дополнительно восстанавливаем database-уровень запрет удаления площадки с
 * замороженными версиями схемы: триггер на halls исчез вместе с таблицей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', static function (Blueprint $table): void {
            if (! Schema::hasColumn('venues', 'capacity')) {
                $table->unsignedInteger('capacity')->nullable()->after('hall_name');
            }
            if (! Schema::hasColumn('venues', 'width')) {
                $table->unsignedInteger('width')->nullable()->after('capacity');
            }
            if (! Schema::hasColumn('venues', 'height')) {
                $table->unsignedInteger('height')->nullable()->after('width');
            }
            if (! Schema::hasColumn('venues', 'exterior_photo_url')) {
                $table->string('exterior_photo_url', 2048)->nullable()->after('height');
            }
            if (! Schema::hasColumn('venues', 'interior_photo_url')) {
                $table->string('interior_photo_url', 2048)->nullable()->after('exterior_photo_url');
            }
        });

        // Legacy-строки, где venue_id не был заполнен миграцией 000100.
        DB::statement('UPDATE hall_schema_versions SET venue_id = hall_id WHERE venue_id IS NULL');

        DB::statement('DROP VIEW IF EXISTS halls');
        DB::statement(<<<'SQL'
            CREATE VIEW halls AS
            SELECT v.id,
                   v.public_id,
                   v.id AS venue_id,
                   COALESCE(v.hall_name, v.name) AS name,
                   v.description AS description,
                   v.city AS city,
                   v.address AS address,
                   v.exterior_photo_url AS exterior_photo_url,
                   v.interior_photo_url AS interior_photo_url,
                   v.capacity AS capacity,
                   v.width AS width,
                   v.height AS height,
                   v.status,
                   v.organization_id,
                   v.created_at,
                   v.updated_at
            FROM venues v
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_venue_frozen_schema_delete_guard');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_venue_frozen_schema_delete_guard
            BEFORE DELETE ON venues
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM hall_schema_versions s
                    WHERE s.venue_id = OLD.id
                      AND s.status IN ('published', 'archived')
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Venue has published or archived hall schemas; archive history must be preserved';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_venue_frozen_schema_delete_guard');
        DB::statement('DROP VIEW IF EXISTS halls');
        DB::statement(<<<'SQL'
            CREATE VIEW halls AS
            SELECT v.id,
                   v.public_id,
                   v.id AS venue_id,
                   COALESCE(v.hall_name, v.name) AS name,
                   v.description AS description,
                   v.city AS city,
                   v.address AS address,
                   NULL AS exterior_photo_url,
                   NULL AS interior_photo_url,
                   NULL AS capacity,
                   NULL AS width,
                   NULL AS height,
                   v.status,
                   v.organization_id,
                   v.created_at,
                   v.updated_at
            FROM venues v
        SQL);

        Schema::table('venues', static function (Blueprint $table): void {
            foreach (['interior_photo_url', 'exterior_photo_url', 'height', 'width', 'capacity'] as $column) {
                if (Schema::hasColumn('venues', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
