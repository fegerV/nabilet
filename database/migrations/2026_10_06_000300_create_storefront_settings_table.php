<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки витрины (конструктор афиши).
 *
 * Одна строка на организацию: конфиг целиком лежит в JSON, потому что витрина
 * — это дерево секций с разными наборами полей, и раскладывать его в
 * реляционные таблицы значило бы менять схему при каждом новом виджете.
 *
 * `organization_id = NULL` — глобальный конфиг по умолчанию. Он же отдаётся
 * гостевой витрине, когда организация не определена (до white-label поддомена),
 * поэтому удалять его нельзя: без него витрина осталась бы без секций.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->json('config');
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            // Один конфиг на организацию. NULL в MySQL не участвует в уникальности,
            // поэтому глобальная строка по умолчанию может сосуществовать с
            // конфигами организаций.
            $table->unique(['organization_id'], 'storefront_settings_organization_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_settings');
    }
};
