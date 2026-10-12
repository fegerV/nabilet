<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ck_events_status — словарь `events.status` переезжает в базу.
 *
 * ЧТО БЫЛО НЕ ТАК
 *   `events.status` — последняя колонка, за которой стоит PHP-машина состояний
 *   без CHECK в схеме. `tools/verify-state-machines.php` сообщал об этом на
 *   каждом прогоне — «no CHECK on status — machine is unenforced», — а заметка,
 *   которая повторяется каждый раз, перестаёт читаться.
 *
 *   Отличие от `inventory_items` (014) в том, что здесь дисциплинирован был КОД,
 *   а молчала только база: `EventStateMachine` и `EventStatus` называют одни и
 *   те же шесть значений, и все авторы записей идут через них —
 *   `EventService::publish()/archive()/cancel()/schedule()/complete()` берут
 *   константы `EventStatus`, `EventPublicationService` — `Event::PUBLISHED` и
 *   один литерал `'cancelled'`, дефолт колонки — `'draft'`.
 *
 *   Почему молчание было опасным, а не теоретическим: `status` СОЗНАТЕЛЬНО
 *   отсутствует в `StoreEventRequest` и `UpdateEventRequest` — раньше
 *   `PUT {"status":"published"}` публиковал событие без сеансов в обход
 *   `EventPublicationPolicy`. Теперь защита от этого — «правила нет, значит
 *   `validated()` его выбросит», то есть одна деталь реализации фреймворка.
 *   CHECK — второй замок: даже если поле вернут в правила запроса, база не
 *   примет статус, которого машина не знает.
 *
 *   `EventStateMachine` сам это и записал: написание `cancelled` (две L, как в
 *   orders/sessions/tickets) держалось «по соглашению (и тестами), а не базой».
 *   Больше держать не нужно.
 *
 * ПОЧЕМУ ЗНАЧЕНИЯ НЕ ВЫДУМАНЫ
 *   Это `EventStatus::all()` — тот же список, по которому ходит
 *   `EventStateMachine`, которым рассуждает политика публикации и который
 *   рисует бейдж админки. Ничего, чего код не считал бы допустимым, здесь нет.
 *
 * ПОЧЕМУ НЕ ПРАВКА 2026_09_20_001000
 *   Та миграция уже прошла на всех развёрнутых базах: ограничение, дописанное
 *   туда, существовало бы в бандле и больше нигде. Отдельный файл применяется и
 *   к существующим, и к свежим установкам — ровно поэтому существуют
 *   `2026_10_05_000200_constrain_carts_unique_to_active.php`,
 *   `2026_10_12_000100_add_users_status_check.php` и
 *   `2026_10_12_000200_add_inventory_items_status_check.php`.
 *
 * Зеркала: `nabilet_core_spec/migrations/015_events_status_check.sql`,
 * консолидированные `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и у остальных CHECK-ограничений: на движке, который
 * CHECK не исполняет, оператор молча получил бы необеспеченный инвариант, а
 * `migrate:status` показал бы «Ran».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED add_events_status_check: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. `events.status` therefore accepts any string,\n"
                . "    and ck_events_status is NOT enforced. migrate:status will still show\n"
                . "    this migration as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        DB::statement(
            "ALTER TABLE events ADD CONSTRAINT ck_events_status "
            . "CHECK (status IN ('draft','scheduled','published','completed','cancelled','archived'))"
        );
    }

    /**
     * Драйверы, которые действительно исполняют CHECK на существующей таблице.
     *
     * MySQL 8.0.16+ и MariaDB 10.2.1+. Более старый MySQL разбирает и молча
     * игнорирует CHECK — именно этот случай гард и делает слышимым.
     */
    private function enforcesCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        // Необратимо по замыслу: снятие инварианта ослабляет базу.
    }
};
