<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отложенные действия ИИ-агента: подготовил агент → одобрил человек → исполнил агент.
 *
 *argent_actions — и журнал решений, и барьер: агент НИКОГДА не исполняет
 * денежное/публикующее действие сразу. В prepare-фазе сохраняется неизменяемый
 * payload и короткое резюме; человек видит его в админке и одобряет; в confirm-
 * фазе сервер исполняет ровно сохранённый payload, а не то, что агент прислал
 * повторно. Повторный confirm возвращает прежний результат (идемпотентность).
 *
 * Идемпотентность миграции — как у соседних: на хостинге миграции иногда
 * применяются дважды при возобновлении прерванной установки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_actions')) {
            return;
        }

        Schema::create('agent_actions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('action', 64);
            $table->string('capability', 64);
            $table->json('payload_json');
            $table->text('summary');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->dateTime('executed_at', 6)->nullable();
            $table->json('result_json')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['public_id'], 'uq_agent_actions_public_id');
            $table->index(['organization_id', 'status'], 'idx_agent_actions_org_status');
            $table->index(['status', 'expires_at'], 'idx_agent_actions_status_expiry');
        });
    }

    public function down(): void
    {
        // Исторический журнал решений намеренно не удаляется.
    }
};
