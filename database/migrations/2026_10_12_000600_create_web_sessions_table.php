<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HTTP-сессии Laravel (витрина и админ-SPA) — выделенная таблица.
 *
 * ДЕФЕКТ. config/session.php указывал хранилищу сессий таблицу `sessions`, но в
 * этой схеме `sessions` — это доменная таблица сеансов событий (колонки
 * event_id, venue_id, hall_id, schema_version_id, starts_at…), а не хранилище
 * HTTP-сессий. Из-за этого SESSION_DRIVER=database ломал web-маршруты:
 * посредник StartSession падал при записи сессии → корень отдавался с HTTP 500
 * (тело уже отправлено = SPA, поэтому баг маскировался под «HTML есть, но
 * статус 500»). Гвард `api` не страдал, т.к. использует отдельную `user_sessions`
 * (Bearer-токены, session_token-драйвер).
 *
 * ФИКС. Выделенная таблица `web_sessions`. Это принадлежность фреймворка, как
 * jobs / failed_jobs / personal_access_tokens: она остаётся app-only и
 * перечислена в knownExtensions['tables'] (tools/verify-migrations.php) рядом с
 * ними, поэтому счётчики Core-спеки (73 таблицы) не меняются и
 * nabilet_core_spec/migrations.sql трогать не нужно.
 *
 * user_id — БЕЗ внешнего ключа, намеренно. verify-migrations требует точного
 * совпадения FOREIGN KEY со спецификацией; app-only таблица не может добавить FK
 * вне спека, иначе проверка «same foreign keys» падает. Сборка мусора сессий и
 * так очищает устаревшие записи по last_activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_sessions')) {
            return;
        }

        Schema::create('web_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_sessions');
    }
};
