<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь мероприятия с шаблоном билета.
 *
 * ЗАЧЕМ
 *
 * До этой миграции шаблон билета существовал сам по себе: таблица
 * `ticket_templates` была, конструктор рисовал макет, но НИЧЕГО не связывало
 * макет с конкретным концертом. То есть «индивидуальный билет под каждое
 * мероприятие» был невозможен в принципе — при выпуске билета неоткуда было
 * взять, какой макет применить.
 *
 * ПОЧЕМУ КОЛОНКА НА `events`, А НЕ НА `sessions`
 *
 * Макет — свойство мероприятия, а не сеанса. У концерта с двумя сеансами
 * (например, дневным и вечерним) билет должен выглядеть одинаково; иначе
 * покупатель получает два разных билета на один и тот же концерт и решает,
 * что один из них поддельный.
 *
 * ПОЧЕМУ `NULL` РАЗРЕШЁН
 *
 * Шаблон — необязательная настройка. Мероприятие без шаблона обязано работать:
 * оно получает стандартный вид билета (см. `TicketTemplateResolver`). Сделать
 * колонку NOT NULL значило бы потребовать шаблон ДО создания мероприятия —
 * то есть заблокировать продажу ради оформления.
 *
 * ПОЧЕМУ `ON DELETE SET NULL`, А НЕ `CASCADE`
 *
 * Удаление шаблона не должно удалять концерты. `CASCADE` здесь означал бы, что
 * администратор, убирая неудачный макет, стирает мероприятие вместе с заказами
 * и билетами — необратимо и без единого предупреждения. `SET NULL` возвращает
 * такие мероприятия к стандартному билету: продажа продолжается, а билеты,
 * которые уже ушли покупателям, остаются действительными, потому что в них
 * лежит подписанный `qr_payload`, а не ссылка на макет.
 *
 * Колонка вне Core-спеки: `nabilet_core_spec` описывает домен, а связь
 * «мероприятие ↔ оформление билета» — продуктовая надстройка. Внесена в
 * APP_OWNED_COLUMNS и knownExtensions (tools/verify-migrations.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        if (Schema::hasColumn('events', 'ticket_template_id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedBigInteger('ticket_template_id')
                ->nullable()
                ->after('category_id');

            // Имя индекса задано явно: иначе Laravel выведет его из имён колонок
            // и таблицы, и на длинном имени (`events_ticket_template_id_foreign`)
            // MySQL может упереться в предел длины идентификатора 64 символа.
            $table->index('ticket_template_id', 'idx_events_ticket_template');

            $table->foreign('ticket_template_id', 'fk_events_ticket_template')
                ->references('id')
                ->on('ticket_templates')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('events', 'ticket_template_id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropForeign('fk_events_ticket_template');
            $table->dropIndex('idx_events_ticket_template');
            $table->dropColumn('ticket_template_id');
        });
    }
};
