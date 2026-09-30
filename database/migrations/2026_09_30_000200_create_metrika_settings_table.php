<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переопределяемые админом настройки Яндекс Метрики (интеграция с Директом).
 * key/value: counter_id, goals (JSON), currency и т.д. Значения хранятся в
 * JSON-кодированном виде; читаются сервисом MetrikaSettings поверх config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrika_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrika_settings');
    }
};
