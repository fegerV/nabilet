<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблицы: cache, cache_locks
 *
 * Инфраструктурные таблицы rate-limit'ера/кеша. Порядок перед `identity` —
 * преднамеренный: это системные таблицы платформы, а не домен-модель.
 *
 * Иными словами: никакая другая миграция их не создавала — проект с дефолтом
 * `CACHE_STORE=file` работал, а поддержку `CACHE_STORE=database` обещал
 * (`NABILET_ALLOW_DATABASE_CACHE=true`, мастер установки, документация), но на
 * первом же `throttle` падал `Base table or view not found: 1146` (найдено
 * 2026-11-04 прогоном установщика на свежей копии кода: после успешной
 * установки повторный POST /install ушёл в 500 не из-за защиты, а из-за
 * отсутствия таблицы `cache`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
