<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Перед созданием базовых таблиц убрать views от более поздней миграции.
 *
 * Laravel `migrate:fresh` удаляет ТАБЛИЦЫ, но не VIEW. Без этого шага после
 * первого прогона тестов базовая миграция 005 падала бы «table tickets already
 * exists» — представление с таким именем оставалось в БД.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS ticket_scans');
        DB::statement('DROP VIEW IF EXISTS tickets');
    }

    public function down(): void
    {
        // no-op
    }
};
