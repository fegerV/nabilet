<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 5 «Клуба зрителей»: розыгрыши «счастливый билет»
 * (`docs/LOYALTY-PLAN.md`).
 *
 * ЗАЧЕМ БИЛЕТ — ЭТО ЗАКАЗ
 *   Покупатель по умолчанию — гость без аккаунта: «одна строка на человека»
 *   потеряла бы всех, кто так и не завёл аккаунт. Заказ есть у обоих, и в
 *   заказе есть адрес (`customer_email`, NOT NULL), по которому победителя
 *   находят. Поэтому `UNIQUE (raffle_id, order_id)`, а не `(raffle_id,
 *   user_id)`.
 *
 * ЗАЧЕМ ОКНО ИЗ ДВУХ ДАТ
 *   «Заказ оплачен в период розыгрыша» проверяется в момент ОПЛАТЫ
 *   (`orders.paid_at`), а не в момент тиража: иначе розыгрыш пришлось бы
 *   пересчитывать на каждое изменение статуса заказа. NULL граница —
 *   «без ограничения», а не магическая дата: заказ, оплаченный до создания
 *   розыгрыша, легально участвует в розыгрыше «за всё время».
 *
 * ЗАЧЕМ ТИРАЖ РУЧНОЙ
 *   Решение заказчика: организатор нажимает «Провести тираж» в админке.
 *   В схеме для этого достаточно статуса `drawn` + `drawn_at` — планировщик,
 *   когда появится, будет менять `status`, а не жить в отдельном флаге.
 *
 * ЗАЧЕМ ПОБЕДИТЕЛИ — ОТДЕЛЬНАЯ ТАБЛИЦА
 *   Запись (`raffle_entries`) создаёт оплата, победителя (`raffle_winners`) —
 *   человек кнопкой. Разные авторы и разное время; отметка «победитель» на
 *   записи означала бы, что оплата (или её повтор) пишет в колонку
 *   победителя. Плюс один участник может побеждать в разных розыгрышах —
 *   «победитель» отношение, а не атрибут.
 *
 * ПОЧЕМУ CHECK — СЫРЫМ DDL
 *   Blueprint не умеет навешивать CHECK на таблицу. `ck_raffles_status` и
 *   `ck_raffle_prizes_place` ставятся сырыми операторами — тем же приёмом,
 *   что `021_referrals.php`. Гейт «Core column defaults match» при этом не
 *   затронут: новых колонок с DEFAULT у существующих таблиц здесь нет, а
 *   DEFAULT `raffles.status` объявлен в собственном CREATE TABLE.
 *
 * ЗЕРКАЛО: `nabilet_core_spec/migrations/022_raffles.sql`, консолидированные
 * `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и остальные CHECK (см. `019_bonus_ledger.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('raffles')) {
            Schema::create('raffles', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);
                $table->string('title');
                $table->text('description')->nullable();
                // Словарь статусов закрыт CHECK ниже (см. заголовок файла).
                $table->string('status', 16)->default('draft');
                // Границы окна nullable: NULL — «без ограничения», а не
                // магическая дата. Окно проверяется в момент оплаты.
                $table->dateTime('starts_at', 6)->nullable();
                $table->dateTime('ends_at', 6)->nullable();
                $table->dateTime('drawn_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->unique(['public_id'], 'uq_raffles_public_id');
                // «Принимающие участников» читается на каждой оплате заказа.
                $table->index(['status'], 'idx_raffles_status');
            });
        }

        if (! Schema::hasTable('raffle_prizes')) {
            Schema::create('raffle_prizes', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);
                $table->unsignedBigInteger('raffle_id');
                // Место в схеме, а не в сортировке: порядок сортировки
                // меняется от запроса к запросу, место — свойство приза.
                $table->unsignedSmallInteger('place');
                $table->string('title');
                $table->text('description')->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->unique(['public_id'], 'uq_raffle_prizes_public_id');
                // Одно призовое место — один приз: «два первых места»
                // сделали бы тираж неопределённым.
                $table->unique(['raffle_id', 'place'], 'uq_raffle_prizes_place');

                $table->foreign('raffle_id', 'fk_raffle_prizes_raffle')
                    ->references('id')->on('raffles')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('raffle_entries')) {
            Schema::create('raffle_entries', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);
                $table->unsignedBigInteger('raffle_id');
                $table->unsignedBigInteger('order_id');
                // Снимок покупателя на момент входа: «мои участия» ищут по
                // нему. SET NULL — заказ остаётся билетом и после удаления
                // аккаунта.
                $table->unsignedBigInteger('user_id')->nullable();
                // Only `created_at`: запись правится при создании и никогда
                // после (см. заголовок файла).
                $table->dateTime('created_at', 6);

                $table->unique(['public_id'], 'uq_raffle_entries_public_id');
                // Инвариант идемпотентности: повтор джобы, повторная запись
                // статуса `paid` и гонка двух оплат дают одну строку.
                $table->unique(['raffle_id', 'order_id'], 'uq_raffle_entries_order');

                $table->index(['user_id'], 'idx_raffle_entries_user');

                $table->foreign('raffle_id', 'fk_raffle_entries_raffle')
                    ->references('id')->on('raffles')->cascadeOnDelete();
                // Заказ удалён — билета нет: участие производно от заказа.
                $table->foreign('order_id', 'fk_raffle_entries_order')
                    ->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('user_id', 'fk_raffle_entries_user')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('raffle_winners')) {
            Schema::create('raffle_winners', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);
                $table->unsignedBigInteger('raffle_id');
                $table->unsignedBigInteger('raffle_prize_id');
                $table->unsignedBigInteger('entry_id');
                // Снимок победителя на момент тиража: к тому моменту аккаунт
                // мог исчезнуть, и письмо всё равно должно быть кому адресовано.
                $table->unsignedBigInteger('user_id')->nullable();
                // Письмо победителю ушло: идемпотентность уведомления и
                // признак «можно отправить повторно» в админке.
                $table->dateTime('notified_at', 6)->nullable();
                $table->dateTime('created_at', 6);

                $table->unique(['public_id'], 'uq_raffle_winners_public_id');
                // На место — один победитель.
                $table->unique(['raffle_id', 'raffle_prize_id'], 'uq_raffle_winners_prize');
                // Один билет — одно место в розыгрыше: тираж идёт без возврата
                // (`Domain\RaffleDraw`), и ключ держит это инвариантом схемы.
                $table->unique(['raffle_id', 'entry_id'], 'uq_raffle_winners_entry');

                $table->foreign('raffle_id', 'fk_raffle_winners_raffle')
                    ->references('id')->on('raffles')->cascadeOnDelete();
                $table->foreign('raffle_prize_id', 'fk_raffle_winners_prize')
                    ->references('id')->on('raffle_prizes')->cascadeOnDelete();
                $table->foreign('entry_id', 'fk_raffle_winners_entry')
                    ->references('id')->on('raffle_entries')->cascadeOnDelete();
                $table->foreign('user_id', 'fk_raffle_winners_user')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED raffle CHECKs: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. The tables are still created, but\n"
                . "    ck_raffles_status and ck_raffle_prizes_place\n"
                . "    are NOT enforced. migrate:status will still show this migration\n"
                . "    as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        DB::statement(
            "ALTER TABLE raffles ADD CONSTRAINT ck_raffles_status CHECK ("
            . "status IN ('draft','active','drawn','cancelled'))"
        );

        DB::statement(
            'ALTER TABLE raffle_prizes ADD CONSTRAINT ck_raffle_prizes_place CHECK ('
            . 'place >= 1)'
        );
    }

    /**
     * Драйверы, которые действительно исполняют CHECK на существующей таблице:
     * MySQL 8.0.16+ и MariaDB 10.2.1+. Тот же гард, что у `019` и `021`.
     */
    private function enforcesCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        // Необратимо по замыслу: `raffle_winners` ссылаются на билеты и
        // призы, и снятие таблиц в обратном порядке FK не позволит. Откат
        // розыгрыша — операция организатора (статус `cancelled`), а не
        // миграции: удаление таблиц стирало бы факт проведения тиража.
    }
};
