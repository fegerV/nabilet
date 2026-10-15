<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 1 «Клуба зрителей»: избранное и подписки (`docs/LOYALTY-PLAN.md`).
 *
 * ЗАЧЕМ ДВЕ ТАБЛИЦЫ, А НЕ ОДНА С ФЛАГОМ
 *   `event_favorites` и `event_follows` структурно одинаковы и намеренно разделены.
 *   Избранное — личный список «хочу посмотреть», писем не порождает. Подписка —
 *   запрос на уведомления, порождает. Одна таблица с флагом заставила бы отвечать
 *   на вопрос «получает ли человек письма?» чтением флага, который никто не
 *   проверяет, и любая рассылка по «избранному» начала бы присылать письма тем,
 *   кто их не просил.
 *
 * ЗАЧЕМ `follow_notifications`
 *   Журнал идемпотентности отправки. Сеансы появляются регулярно, и одно письмо не
 *   должно уйти дважды: `uq_follow_notifications_dedupe` превращает повторную
 *   отправку в конфликт вставки, который обрабатывается. SELECT-then-INSERT здесь
 *   недостаточно — между ними влезает второй процесс; это та же защита, что
 *   `uq_order_reminders_order` у напоминаний (`012_application_domain.sql`).
 *
 * `follow_notifications.status` — словарь `queued|sent|failed` взят у
 * `notifications.status` (`ck_notifications_status`, сплит 017): новая колонка
 * состояния отправки не заслуживает нового словаря.
 *
 * Ни одна таблица не участвует в расчёте сумм: фаза выкатывается независимо от
 * чекаута и не может сдвинуть `ck_orders_amounts`.
 *
 * Зеркало: `nabilet_core_spec/migrations/018_loyalty_foundation.sql`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_favorites')) {
            Schema::create('event_favorites', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('event_id');

                $table->dateTime('created_at', 6);

                // Повторное «❤️» — тот же факт, а не вторая запись.
                $table->unique(['user_id', 'event_id'], 'uq_event_favorites_user_event');
                $table->unique(['public_id'], 'uq_event_favorites_public_id');

                // «Моё избранное» — список одного пользователя по времени.
                $table->index(['user_id', 'created_at'], 'idx_event_favorites_user_created');

                $table->foreign('user_id', 'fk_event_favorites_user')
                    ->references('id')->on('users')->cascadeOnDelete();

                $table->foreign('event_id', 'fk_event_favorites_event')
                    ->references('id')->on('events')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('event_follows')) {
            Schema::create('event_follows', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('event_id');

                $table->dateTime('created_at', 6);

                $table->unique(['user_id', 'event_id'], 'uq_event_follows_user_event');
                $table->unique(['public_id'], 'uq_event_follows_public_id');

                // «Кому писать о новом сеансе этого мероприятия».
                $table->index(['event_id', 'created_at'], 'idx_event_follows_event');

                $table->foreign('user_id', 'fk_event_follows_user')
                    ->references('id')->on('users')->cascadeOnDelete();

                $table->foreign('event_id', 'fk_event_follows_event')
                    ->references('id')->on('events')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('follow_notifications')) {
            Schema::create('follow_notifications', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('follow_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('session_id');

                // Код шаблона из `notification_templates.code`.
                $table->string('template_code', 100);
                $table->string('status', 32)->default('queued');

                // Строка в `notifications`, которая уже несла письмо.
                $table->unsignedBigInteger('notification_id')->nullable();

                $table->dateTime('sent_at', 6)->nullable();
                $table->text('error_message')->nullable();

                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                // Ключ идемпотентности: подписка × сеанс × шаблон — одно письмо.
                $table->unique(['follow_id', 'session_id', 'template_code'], 'uq_follow_notifications_dedupe');
                $table->unique(['public_id'], 'uq_follow_notifications_public_id');

                $table->index(['user_id', 'created_at'], 'idx_follow_notifications_user_created');
                $table->index(['status'], 'idx_follow_notifications_status');

                $table->foreign('follow_id', 'fk_follow_notifications_follow')
                    ->references('id')->on('event_follows')->cascadeOnDelete();

                $table->foreign('user_id', 'fk_follow_notifications_user')
                    ->references('id')->on('users')->cascadeOnDelete();

                $table->foreign('event_id', 'fk_follow_notifications_event')
                    ->references('id')->on('events')->cascadeOnDelete();

                $table->foreign('session_id', 'fk_follow_notifications_session')
                    ->references('id')->on('sessions')->cascadeOnDelete();

                $table->foreign('notification_id', 'fk_follow_notifications_notification')
                    ->references('id')->on('notifications')->nullOnDelete();
            });

            // Словарь `queued|sent|failed` — как у `notifications.status`
            // (`ck_notifications_status`). Blueprint CHECK на существующей
            // таблице не выражает, поэтому сырым DDL — как в остальных сплитах
            // словарей (013–017).
            DB::statement(
                'ALTER TABLE follow_notifications'
                . " ADD CONSTRAINT ck_follow_notifications_status"
                . " CHECK (status IN ('queued','sent','failed'))"
            );
        }
    }

    public function down(): void
    {
        // Необратимо намеренно: удаление таблиц снимает и идемпотентность рассылки,
        // и сохранённые списки покупателя. Откатываемся новой миграцией.
    }
};
