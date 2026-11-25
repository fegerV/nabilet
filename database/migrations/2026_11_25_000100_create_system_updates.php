<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал self-update (docs/ONE_CLICK_UPDATE.md).
 *
 * Таблица системная, не арендаторская: обновление меняет код всей установки,
 * а не данные одной организации. Поэтому у неё нет organization_id и нет
 * внешнего ключа на users — администратор может быть удалён, а журнал должен
 * сохранить факт действия (кто именно — отдельно пишется в security.log).
 *
 * Статус дублируется в `storage/framework/updates/<id>.json`: если миграция
 * изменила БД так, что вычитать запись нельзя, аварийный откат всё равно знает,
 * что делать.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_updates')) {
            return;
        }

        Schema::create('system_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('from_version', 50);
            $table->string('to_version', 50);
            // feed | upload
            $table->string('source', 16);
            // preparing | ready | applying | migrating | verifying | done |
            // failed | failed_after_swap | rolling_back | rolled_back | cancelled
            $table->string('status', 32)->default('preparing');
            $table->string('step', 64)->nullable();
            $table->string('artifact', 255)->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->string('backup_status', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('locked_at', 6)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['status'], 'idx_system_updates_status');
            $table->index(['created_at'], 'idx_system_updates_created');
        });
    }

    public function down(): void
    {
        // Необратимо по дизайну: журнал опасных действий не удаляется миграцией.
        // Удаление таблицы оторвёт историю обновлений от security.log.
    }
};
