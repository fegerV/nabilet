<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Связь «пользователь ↔ внешний аккаунт» для соц-входа (ТЗ §17).
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ КОЛОНКИ В `users`. Один пользователь может
 * привязать несколько провайдеров (вошёл через VK, позже привязал Яндекс), а
 * колонки `provider`/`provider_id` в `users` хранят ровно один. Пара
 * (`provider`, `provider_user_id`) — это идентичность у провайдера, и она
 * обязана быть уникальной: два разных пользователя не могут делить один
 * внешний аккаунт, иначе вход по нему неоднозначен.
 *
 * Таблица — расширение ПРИЛОЖЕНИЯ сверх Core-бандла (в спеке её нет), поэтому
 * она внесена в allowlist `verify-migrations.php` и `verify-models-schema.php`
 * как app-only. Это осознанное решение, а не дрейф схемы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['provider', 'provider_user_id'], 'uq_social_provider_user');
            $table->index('user_id', 'idx_social_user');
            $table->foreign('user_id', 'fk_social_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
