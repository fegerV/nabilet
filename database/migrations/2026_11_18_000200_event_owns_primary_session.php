<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Мероприятие владеет своей основной датой/площадкой/схемой (этап 2).
 *
 * Сеанс остаётся в БД как скрытая техническая тень: на него по-прежнему
 * ссылаются корзины, инвентарь, билеты, чек-ин и аналитика. Пользователь же
 * видит одно мероприятие с датой и ценой, а не «Событие → Сеанс → Билеты».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dateTime('starts_at', 6)->nullable()->after('status');
            $table->dateTime('ends_at', 6)->nullable()->after('starts_at');
            $table->dateTime('sales_start_at', 6)->nullable()->after('ends_at');
            $table->dateTime('sales_end_at', 6)->nullable()->after('sales_start_at');
            $table->string('timezone', 64)->nullable()->after('sales_end_at');
            $table->unsignedBigInteger('venue_id')->nullable()->after('timezone');
            $table->unsignedBigInteger('schema_version_id')->nullable()->after('venue_id');
            $table->unsignedBigInteger('primary_session_id')->nullable()->after('schema_version_id');
            $table->index(['venue_id', 'starts_at'], 'idx_events_venue_starts');
        });

        // Для каждого мероприятия берём самый ранний будущий сеанс, иначе
        // самый поздний прошедший — то же правило, что в EventPageController.
        DB::statement(<<<'SQL'
            UPDATE events e
            JOIN (
                SELECT s.* FROM sessions s
                JOIN (
                    SELECT event_id, MIN(starts_at) AS starts_at
                    FROM sessions
                    WHERE starts_at >= NOW()
                    GROUP BY event_id
                ) next ON next.event_id = s.event_id AND next.starts_at = s.starts_at
            ) primary_session ON primary_session.event_id = e.id
            SET e.starts_at = primary_session.starts_at,
                e.ends_at = primary_session.ends_at,
                e.sales_start_at = primary_session.sales_start_at,
                e.sales_end_at = primary_session.sales_end_at,
                e.timezone = primary_session.timezone,
                e.venue_id = primary_session.venue_id,
                e.schema_version_id = primary_session.schema_version_id,
                e.primary_session_id = primary_session.id
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('event_owns_primary_session is not reversible; restore from a backup.');
    }
};
