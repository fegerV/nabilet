<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сущность «Артист» — постоянный слой SEO-контента (стратегия, B-1).
 *
 * ЗАЧЕМ НОВАЯ СУЩНОСТЬ, ЕСЛИ `event_artists` УЖЕ ЕСТЬ. `event_artists` хранит
 * имя артиста ВНУТРИ события: «Би-2» в одном мероприятии и «БИ-2» в другом —
 * это две несвязанные строки. Страница артиста из неё не выводится: получилась
 * бы одна страница на каждое НАПИСАНИЕ, и каждая — «тонкая» (заголовок плюс
 * одно событие). Google понижает такие страницы, причём понижение
 * распространяется на раздел целиком.
 *
 * ЧТО ДЕЛАЕТ `normalized_name` УНИКАЛЬНЫМ. Это ключ слияния: имя приводится к
 * нижнему регистру, из него убирается пунктуация и схлопываются пробелы, и
 * UNIQUE-индекс гарантирует, что у одного артиста ровно одна строка. Именно
 * это, а не сам факт таблицы, превращает множество написаний в одну страницу.
 * Проверка идёт в БАЗЕ, а не в коде: два процесса синхронизации, стартовавшие
 * одновременно, иначе завели бы дубль — `SELECT` + `INSERT` проигрывает второму
 * процессу (та же причина, по которой `uq_order_reminders_order` живёт в схеме).
 *
 * ПОЧЕМУ НЕТ `organization_id`. Артист — сущность КАТАЛОГА, как
 * `event_categories`: у категорий этой колонки тоже нет. Посадочная страница
 * читает каталог по статусу, а не по организации, и оба представления одних
 * данных (страница и публичный API) обязаны совпадать.
 *
 * ПОЧЕМУ `slug` ГЛОБАЛЬНО УНИКАЛЕН. Ровно по той же причине, по которой
 * глобально уникальным сделали `events.slug` (миграция
 * 2026_10_11_000100_make_event_slug_globally_unique): публичный адрес
 * организации не содержит, поэтому два артиста из разных организаций с одним
 * slug открывали бы произвольного из двух, а их канонические адреса были бы
 * неразличимы. Это промежуточное решение до появления организационных URL
 * (аудит P-03); цена — вторая организация не сможет занять занятый slug.
 *
 * `down()` УДАЛЯЕТ ТАБЛИЦЫ, А НЕ ПЕРЕИМЕНОВЫВАЕТ ИХ. Связь снимается первой:
 * `artist_event` держит внешние ключи на обе таблицы, и обратный порядок упал бы
 * на ограничении.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('artists')) {
            Schema::create('artists', function (Blueprint $table): void {
                $table->id();
                // Адрес страницы. `Str::slug()` транслитерирует неинтуитивно
                // (`Тюмень` → `tiumen`), поэтому адрес — публичный контракт, и в
                // тестах он пишется явно, а не через `Str::slug()`.
                $table->string('slug', 150);
                // Отображаемое имя: то написание, которое администратор утвердил.
                $table->string('name');
                // Ключ слияния. См. докблок выше — на нём держится вся модель.
                $table->string('normalized_name');
                $table->string('genre')->nullable();
                $table->text('bio')->nullable();
                $table->string('photo')->nullable();
                $table->json('social_links')->nullable();
                $table->string('seo_title')->nullable();
                $table->string('seo_description', 500)->nullable();
                // Снятый с публикации артист не должен открываться по адресу —
                // тем же правилом, что у площадки и категории.
                $table->string('status', 32)->default('active');
                $table->timestamps();

                $table->unique('slug', 'uq_artists_slug');
                $table->unique('normalized_name', 'uq_artists_normalized_name');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('artist_event')) {
            Schema::create('artist_event', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                // Дублируются из `event_artists` намеренно: страница артиста
                // сортирует события по этим полям, а join на программную строку
                // события каждый раз стоил бы лишнего запроса. Единственный
                // писатель — `artists:sync`, поэтому расходиться нечему.
                $table->boolean('is_headliner')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                // Один артист — одно событие, один раз. Идемпотентность
                // синхронизации: повторный прогон обновляет строку, а не плодит
                // вторую.
                $table->unique(['artist_id', 'event_id'], 'uq_artist_event');
                $table->index('event_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_event');
        Schema::dropIfExists('artists');
    }
};
