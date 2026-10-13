<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 2 «Клуба зрителей»: бонусный счёт (`docs/LOYALTY-PLAN.md`).
 *
 * ЗАЧЕМ ДВЕ ТАБЛИЦЫ: БАЛАНС И ЖУРНАЛ
 *   `bonus_accounts.balance` — текущее состояние, ровно одна строка на человека
 *   (`UNIQUE (user_id)` — инвариант, а не оптимизация: две строки означали бы два
 *   баланса). `bonus_transactions` — append-only журнал: строки не правятся и не
 *   удаляются, потому что «почему у меня 340 бонусов» должен объяснять набор
 *   строк, а не догадка. Баланс пересчитывается из журнала; журнал из баланса —
 *   нет. Отсюда знак: `delta` знаковый, `reason` называет ПРИЧИНУ, а не
 *   направление — слить их значило бы потерять одно из двух.
 *
 * ЕДИНИЦА ИЗМЕРЕНИЯ
 *   `balance` и `delta` — в минорных единицах, как и все деньги в этой схеме
 *   (`Money`: int minor units, без float). `orders.bonus_amount` стоит в одном
 *   CHECK с `subtotal_amount` и `total_amount`, и колонка в рублях рядом с
 *   колонками в копейках сделала бы этот CHECK бессмысленным. Курс отображения
 *   («1 бонус = 1 ₽») — правило витрины: 1 ₽ = 100 минорных единиц, делит их
 *   представление, не база.
 *
 * ИДЕМПОТЕНТНОСТЬ: `dedupe_key`, А НЕ `UNIQUE (order_id, reason)`
 *   Начисление обязано пройти ровно один раз на заказ, и «SELECT, потом INSERT»
 *   не годится — между ними влезает второй воркер (та же защита, что
 *   `uq_follow_notifications_dedupe`). Но `UNIQUE (order_id, reason)` закрыл бы и
 *   частичный возврат — законную ВТОРУЮ компенсацию по тому же заказу. Поэтому
 *   ключ идемпотентности — необязательная колонка: писатель сам объявляет строку
 *   идемпотентной (`accrual:{order_id}`), а строки без ключа друг другу не
 *   мешают (MySQL допускает сколько угодно NULL в UNIQUE). Тот же приём уже есть
 *   в схеме: `refunds.provider_refund_id`, `payments.provider_payment_id`.
 *
 * ПОЧЕМУ КОЛОНКА — BLUEPRINT, А CHECK — СЫРЫМ DDL
 *   `tools/verify-migrations.php` собирает форму таблицы из объявлений Blueprint,
 *   и колонка, добавленная сырым `ALTER TABLE ... ADD COLUMN`, доезжает до
 *   рекордера БЕЗ DEFAULT — после чего ратчет «Core column defaults match»
 *   требует DEFAULT, который в миграции есть. Проверено на
 *   `2026_10_12_000400_add_login_logs_event.php`: именно на этом тот файл и
 *   падал. Ограничение Blueprint выразить не может, поэтому CHECK — сырым DDL.
 *
 * ПОЧЕМУ `ck_orders_amounts` ПЕРЕИЗДАЁТСЯ ЗДЕСЬ, А НЕ ПРАВИТСЯ В 2026_09_20
 *   Та миграция давно прошла на развёрнутых базах: расширенный там CHECK
 *   существовал бы у свежих установок и не существовал у остальных. CHECK в
 *   MySQL 8 нельзя изменить — только снять и создать заново. Снятие безопасно
 *   для существующих строк: у всех уже `bonus_amount = 0` (DEFAULT ниже), а
 *   условие `>= 0` для нуля истинно.
 *
 * Зеркала: `nabilet_core_spec/migrations/019_bonus_ledger.sql`, консолидированные
 * `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и у остальных CHECK: на движке, который CHECK не
 * исполняет, оператор молча получил бы необеспеченные инварианты по деньгам, а
 * `migrate:status` показал бы «Ran».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bonus_accounts')) {
            Schema::create('bonus_accounts', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('user_id');

                // Минорные единицы. BIGINT, а не INT: баланс живёт годами.
                $table->bigInteger('balance')->default(0);
                $table->char('currency', 3)->default('RUB');

                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                // Одна строка на человека — инвариант, не оптимизация.
                $table->unique(['user_id'], 'uq_bonus_accounts_user');
                $table->unique(['public_id'], 'uq_bonus_accounts_public_id');

                $table->foreign('user_id', 'fk_bonus_accounts_user')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('bonus_transactions')) {
            Schema::create('bonus_transactions', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('user_id');

                // NULL для подарков (`registration`, `first_purchase`) и истечения:
                // они не привязаны к заказу. SET NULL — удаление заказа не должно
                // стирать историю бонусов покупателя.
                $table->unsignedBigInteger('order_id')->nullable();

                // Знаковое число в минорных единицах: плюс — начислили, минус — списали.
                $table->bigInteger('delta');
                $table->string('reason', 32);

                // Ключ идемпотентности. NULL — движение повторяемо (частичные
                // возвраты); `accrual:{order_id}` — ровно одно на заказ. 191, а не
                // 255: значение синтезируется кодом, вводить его некому.
                $table->string('dedupe_key', 191)->nullable();

                $table->dateTime('created_at', 6);

                $table->unique(['public_id'], 'uq_bonus_transactions_public_id');
                $table->unique(['dedupe_key'], 'uq_bonus_transactions_dedupe');

                // «История движений» — список одного человека по времени.
                $table->index(['user_id', 'created_at'], 'idx_bonus_transactions_user_created');
                // «Что журнал знает про этот заказ» — разбор начисления и компенсации.
                $table->index(['order_id'], 'idx_bonus_transactions_order');

                $table->foreign('user_id', 'fk_bonus_transactions_user')
                    ->references('id')->on('users')->cascadeOnDelete();

                $table->foreign('order_id', 'fk_bonus_transactions_order')
                    ->references('id')->on('orders')->nullOnDelete();
            });
        }

        $this->addBonusAmountToOrders();

        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED bonus ledger CHECKs: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. The tables and orders.bonus_amount are still\n"
                . "    added, but ck_bonus_accounts_balance, ck_bonus_transactions_reason\n"
                . "    and the widened ck_orders_amounts are NOT enforced. migrate:status\n"
                . "    will still show this migration as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        // Снять и создать заново: MySQL 8 не умеет ALTER CHECK. Безопасно для
        // существующих строк — bonus_amount у них 0 (DEFAULT), а 0 >= 0.
        DB::statement('ALTER TABLE orders DROP CONSTRAINT ck_orders_amounts');

        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT ck_orders_amounts CHECK ('
            . 'subtotal_amount >= 0 AND discount_amount >= 0 AND fee_amount >= 0 '
            . 'AND bonus_amount >= 0 AND total_amount >= 0)'
        );

        DB::statement(
            'ALTER TABLE bonus_accounts'
            . ' ADD CONSTRAINT ck_bonus_accounts_balance CHECK (balance >= 0)'
        );

        // Словарь фазы 2 целиком, а не по мере появления писателей: расширение
        // словаря — это ровно та правка CHECK, от которой предостерегает план.
        DB::statement(
            'ALTER TABLE bonus_transactions'
            . " ADD CONSTRAINT ck_bonus_transactions_reason CHECK (reason IN ("
            . "'accrual','registration','first_purchase','spend','refund','expiry'))"
        );
    }

    /**
     * Колонка — через Blueprint (см. заголовок: сырой ADD COLUMN теряет DEFAULT
     * в рекордере `verify-migrations.php`).
     */
    private function addBonusAmountToOrders(): void
    {
        if (Schema::hasColumn('orders', 'bonus_amount')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->bigInteger('bonus_amount')
                ->default(0)
                ->after('discount_amount');
        });
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
        // Необратимо по замыслу: снятие журнала стирает историю начислений и
        // списаний, а снятие CHECK ослабляет инварианты по деньгам.
    }
};
