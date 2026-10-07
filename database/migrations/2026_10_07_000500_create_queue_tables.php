<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблицы очереди: jobs, failed_jobs.
 *
 * ПОЧЕМУ ОНИ ВООБЩЕ ПОЯВИЛИСЬ. Драйвер очереди задан как `database`
 * (config/queue.php, QUEUE_CONNECTION=database), но самой таблицы `jobs` в
 * схеме не было: любая `dispatch()` падала бы с «Table 'nabilet.jobs' doesn't
 * exist», а письма и вебхуки — молча не уходить. Транзакционная почта и
 * исходящие вебхуки держатся на очередях, поэтому таблица — часть P0, а не
 * инфраструктурная мелочь.
 *
 * Это НЕ таблицы Core-спеки: `migrations.sql` описывает домен билетов, а очереди
 * — принадлежность фреймворка. Поэтому они внесены в APP_ONLY_TABLES
 * (tools/verify-migrations.php) рядом с `personal_access_tokens` и не меняют
 * счётчики таблиц/колонок спецификации.
 *
 * Схема взята канонической для Laravel: `reserved_at`/`available_at`/
 * `created_at` — unix-секунды, и воркер рассчитывает именно на такой формат.
 * Изменять типы нельзя, иначе выборка готовых джоб перестанет работать.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        // failed_jobs — единственное место, где видна доставка, исчерпавшая
        // попытки. Без неё пропавший вебхук не найти: запись в
        // webhook_deliveries останется без объяснения.
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Необратимо намеренно: удаление таблицы очереди теряет информацию о
        // недоставленных письмах и вебхуках. Откатываемся новой миграцией.
    }
};
