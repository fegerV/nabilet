<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 4 «Клуба зрителей»: реферальная программа (`docs/LOYALTY-PLAN.md`).
 *
 * ЗАЧЕМ ДВЕ ТАБЛИЦЫ
 *   Код приглашения — свойство приглашающего (`referral_codes`, одна строка на
 *   человека), приглашение — строка на каждого друга (`referrals`, одна строка
 *   на пару «пригласивший + email друга»). Ссылку `/r/{code}` нужно разрешать
 *   ДО того, как друг появился в базе, поэтому код не может жить в строках
 *   приглашений: у человека без приглашений ссылки всё равно должна быть.
 *
 * ПОЧЕМУ EMAIL, А НЕ USER_ID, ОПРЕДЕЛЯЕТ ДРУГА
 *   Покупатель по умолчанию — гость без аккаунта: ссылку открывают до
 *   регистрации, и `user_id` в этот момент не существует. Адрес известен и до,
 *   и после (`customer_email` NOT NULL, аккаунт заводится на чекауте), поэтому
 *   UNIQUE (inviter_user_id, invited_email) — инвариант идемпотентности, а
 *   `invited_user_id` — необязательное уточнение на потом.
 *
 * ПОЧЕМУ СТАТУС, А НЕ ФЛАГИ
 *   `pending` → `registered` → `rewarded` — жизненный цикл приглашения.
 *   Отдельный флаг «бонус выплачен» рядом с флагом «аккаунт есть» разошёлся бы
 *   при первой же частичной записи; одна колонка со словарём (CHECK) так не
 *   может.
 *
 * ПОЧЕМУ CHECK — СЫРЫМ DDL
 *   Blueprint не умеет ни навешивать CHECK на существующую таблицу, ни
 *   переиздавать его: `ck_referrals_status` и расширенный
 *   `ck_bonus_transactions_reason` ставятся сырыми операторами — тем же
 *   приёмом, что `019_bonus_ledger.php`. Гейт «Core column defaults match»
 *   при этом не затронут: новых колонок с DEFAULT у существующих таблиц здесь
 *   нет, а DEFAULT `referrals.status` объявлен в собственном CREATE TABLE.
 *
 * ЗЕРКАЛО: `nabilet_core_spec/migrations/021_referrals.sql`, консолидированные
 * `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и остальные CHECK (см. `019_bonus_ledger.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referral_codes')) {
            Schema::create('referral_codes', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('user_id');

                // 16 символов, а не ULID: код диктуют голосом и переписывают
                // вручную. Алфавит генерации (`Domain\ReferralCode`) исключает
                // визуально похожие символы, поэтому код переживает пересылку.
                $table->string('code', 16);

                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->unique(['public_id'], 'uq_referral_codes_public_id');
                // Код один на человека — свойство приглашающего, а не ссылки.
                $table->unique(['user_id'], 'uq_referral_codes_user');
                // Ссылка `/r/{code}` разрешается без догадок.
                $table->unique(['code'], 'uq_referral_codes_code');

                $table->foreign('user_id', 'fk_referral_codes_user')
                    ->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26);

                $table->unsignedBigInteger('inviter_user_id');

                // Адрес друга в нижнем регистре (приведение — на стороне
                // сервиса): UNIQUE ниже ключуется этой строкой, и «Иванов@»
                // против «иванов@» сделал бы вторым приглашением первое.
                $table->string('invited_email', 255);

                // NULL, пока аккаунта друга нет; SET NULL — удаление аккаунта
                // не стирает факт приглашения.
                $table->unsignedBigInteger('invited_user_id')->nullable();

                $table->string('status', 16)->default('pending');
                $table->dateTime('bonus_paid_at', 6)->nullable();

                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);

                $table->unique(['public_id'], 'uq_referrals_public_id');
                // Инвариант идемпотентности: повторное оформление по той же
                // ссылке находит строку, а не создаёт вторую.
                $table->unique(['inviter_user_id', 'invited_email'], 'uq_referrals_inviter_email');

                // «Приглашения, где друг — этот аккаунт»: награда ищется по нему.
                $table->index(['invited_user_id'], 'idx_referrals_invited_user');

                $table->foreign('inviter_user_id', 'fk_referrals_inviter')
                    ->references('id')->on('users')->cascadeOnDelete();

                $table->foreign('invited_user_id', 'fk_referrals_invited_user')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED referral CHECKs: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. The tables are still created, but\n"
                . "    ck_referrals_status and the widened ck_bonus_transactions_reason\n"
                . "    are NOT enforced. migrate:status will still show this migration\n"
                . "    as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        // Статус приглашения — из закрытого словаря.
        DB::statement(
            "ALTER TABLE referrals ADD CONSTRAINT ck_referrals_status CHECK ("
            . "status IN ('pending','registered','rewarded'))"
        );

        // Переиздание словаря причин журнала: `referral_inviter` и
        // `referral_invited`. Снять и создать заново — MySQL 8 не умеет
        // ALTER CHECK. Снятие безопасно: новое условие надмножество старого.
        DB::statement('ALTER TABLE bonus_transactions DROP CONSTRAINT ck_bonus_transactions_reason');

        DB::statement(
            'ALTER TABLE bonus_transactions ADD CONSTRAINT ck_bonus_transactions_reason CHECK ('
            . "reason IN ('accrual','registration','first_purchase','spend','refund','expiry',"
            . " 'referral_inviter','referral_invited'))"
        );
    }

    /**
     * Драйверы, которые действительно исполняют CHECK на существующей таблице:
     * MySQL 8.0.16+ и MariaDB 10.2.1+. Тот же гард, что у `019_bonus_ledger.php`.
     */
    private function enforcesCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        // Необратимо по замыслу: приглашения — факт, по которому начислены
        // бонусы (`bonus_transactions.reason = referral_*`), и снятие таблиц
        // разорвало бы журнал. Переизданный CHECK не откатывается: узкий
        // словарь причин отверг бы существующие строки `referral_*`.
    }
};
