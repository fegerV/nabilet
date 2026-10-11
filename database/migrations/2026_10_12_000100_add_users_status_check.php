<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ck_users_status — словарь `users.status` переезжает в базу.
 *
 * ЧТО БЫЛО НЕ ТАК
 *   `users.status` — единственная status-колонка в схеме без CHECK и без
 *   словаря, записанного где-либо, кроме одной строки правила валидации внутри
 *   `UserController::update()` (`in:active,inactive,banned`). Отсюда два
 *   следствия, оба подтверждены до этой правки:
 *
 *     1. Бан не мешал войти. `UserService::authenticate()` сверял только хеш
 *        пароля, поэтому `status = 'banned'` отзывал существующие сессии, и тот
 *        же пароль немедленно покупал новую. Отзыв сессий без отказа во входе —
 *        это выход из системы, а не лишение доступа.
 *     2. Любая запись мимо того одного эндпоинта (сидер, скрипт, правка руками
 *        по SQL) могла положить в колонку произвольную строку, и база её
 *        принимала молча.
 *
 * ПОЧЕМУ ЗНАЧЕНИЯ НЕ ВЫДУМАНЫ
 *   Это ровно тот набор, о котором стек уже договорился: его принимает API
 *   (`UserController`), его рисует админка (бейдж «Активен / Неактивен /
 *   Заблокирован») и его же как «лишение доступа» понимает
 *   `UserSessionRevocationTest` (и `banned`, и `inactive` отзывают токены).
 *   Здесь база лишь начинает требовать то, о чём остальной стек уже
 *   договорился, — принцип AD-12: если БД может это отклонить, она должна.
 *
 *   Проверка в коде приехала тем же изменением: словарь живёт в
 *   `Nabilet\Modules\Core\Users\Domain\UserStatus`, `authenticate()` отказывает
 *   всему, кроме `active`, а неизвестное значение трактуется как «доступа нет»
 *   (fail-closed). Правило валидации и решение о входе читают один список и
 *   разойтись больше не могут — в отличие от трёх копий политики паролей.
 *
 * ПОЧЕМУ НЕ ПРАВКА 2026_09_20_001000
 *   Та миграция уже прошла на всех развёрнутых базах: ограничение, дописанное
 *   туда, существовало бы в бандле и больше нигде. Отдельный файл применяется и
 *   к существующим, и к свежим установкам — ровно поэтому существует
 *   `2026_10_05_000200_constrain_carts_unique_to_active.php`.
 *
 * Зеркала: `nabilet_core_spec/migrations/013_users_status_check.sql`,
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
                "\n  ! SKIPPED add_users_status_check: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. `users.status` therefore accepts any string,\n"
                . "    and ck_users_status is NOT enforced. migrate:status will still show\n"
                . "    this migration as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_status CHECK (status IN ('active','inactive','banned'))");
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
