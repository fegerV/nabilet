<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Площадка становится владельцем схемы зала (этап 1 упрощения модели).
 *
 * Раньше: venues → halls → hall_schema_versions, при создании площадки
 * автоматически появлялся «Основной зал». Теперь схема привязывается к
 * площадке напрямую через venue_id. Таблица halls остаётся на этом этапе
 * как legacy-совместимость для старых вызовов и сеансов; она будет удалена
 * после перевода всех читателей на venue_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hall_schema_versions', 'venue_id')) {
            Schema::table('hall_schema_versions', function (Blueprint $table): void {
                $table->unsignedBigInteger('venue_id')->nullable()->after('hall_id');
                $table->index(['venue_id', 'status'], 'idx_schema_venue_status');
            });
        }

        DB::statement(<<<'SQL'
            UPDATE hall_schema_versions sv
            JOIN halls h ON h.id = sv.hall_id
            SET sv.venue_id = h.venue_id
            WHERE sv.venue_id IS NULL
        SQL);
    }

    public function down(): void
    {
        // Этап 1 обратим только из бэкапа: снятие колонки потеряет привязку
        // схем к площадкам, созданным после миграции.
        throw new RuntimeException('venue_owns_schema is not reversible; restore from a backup.');
    }
};
