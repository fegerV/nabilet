<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** `migrate:fresh` удаляет таблицы, но не VIEW — убираем halls перед baselines. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS halls');
    }

    public function down(): void
    {
        // no-op
    }
};
