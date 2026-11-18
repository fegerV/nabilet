<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Площадка = зал = схема (финальный шаг).
 *
 * Таблица halls удаляется. Её заменяет VIEW над venues: id зала совпадает с
 * id площадки, а имя технической строки хранится в venues.hall_name. Весь
 * legacy-код, работающий с Hall, продолжает получать те же поля.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            if (! Schema::hasColumn('venues', 'hall_name')) {
                $table->string('hall_name', 255)->nullable()->after('name');
            }
        });

        if (Schema::hasTable('halls')) {
            DB::statement(<<<'SQL'
                UPDATE venues v
                JOIN halls h ON h.venue_id = v.id
                SET v.hall_name = h.name
                WHERE v.hall_name IS NULL
            SQL);
        }

        // Внешние ключи на halls больше не нужны: hall_id теперь равен venue_id.
        foreach ([
            ['hall_schema_versions', 'fk_schema_hall'],
            ['sessions', 'fk_sessions_hall'],
        ] as [$table, $foreign]) {
            $exists = DB::selectOne(
                'SELECT 1 FROM information_schema.table_constraints '
                . 'WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? LIMIT 1',
                [$table, $foreign],
            );
            if ($exists !== null) {
                Schema::table($table, function (Blueprint $blueprint) use ($foreign): void {
                    $blueprint->dropForeign($foreign);
                });
            }
        }

        if (Schema::hasTable('halls')) {
            DB::statement(<<<'SQL'
                UPDATE hall_schema_versions sv
                JOIN halls h ON h.id = sv.hall_id
                SET sv.hall_id = h.venue_id
            SQL);
            DB::statement(<<<'SQL'
                UPDATE sessions s
                JOIN halls h ON h.id = s.hall_id
                SET s.hall_id = h.venue_id
            SQL);

            Schema::drop('halls');
        }

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
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS halls');

        throw new RuntimeException('halls were replaced by a view; restore from a backup.');
    }
};
