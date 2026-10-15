<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `login_logs.event` + `ck_login_logs_event` — вход и выход становятся различимы.
 *
 * ЧТО БЫЛО НЕ ТАК
 *   `login_logs` писался ровно из одного места — `SessionIssuer::auditLogout()`,
 *   то есть выходом. Отличить строку входа от строки выхода можно было только
 *   косвенно: у входа `failure_code` заполнен, у выхода — NULL. Это верно ровно
 *   до первого писателя, который начнёт писать успешный вход с
 *   `failure_code = NULL`, — и тогда два события неразличимы вовсе.
 *   §3.25 фиксировала это как «у таблицы нет колонки события».
 *
 *   Заодно колонка даёт хранение тому, что домен уже различает: вход
 *   (`SessionIssuer::issue`), выход (`::revoke`) и отказ
 *   (`AuthError('bad_credentials')`). Теперь пишутся все три, и аудит входа
 *   наконец ведётся — раньше таблицаaudit'ила только выход.
 *
 * ЗНАЧЕНИЯ НЕ ВЫДУМАНЫ
 *   `login`, `logout`, `login_failed` — те три события, которые уже различает
 *   домен. CHECK запирает четвёртое: строка без разбора событий — строка,
 *   которую никто не сможет прочитать.
 *
 *   `DEFAULT 'login'` — потому что историческая строка выхода пишется этим же
 *   писателем; вызов всегда задаёт `event` явно, а дефолт защищает запись из
 *   кода, который колонку ещё не знает, от отказа вставки.
 *
 * ПОЧЕМУ НЕ ПРАВКА 001_identity
 *   Та миграция уже прошла на всех развёрнутых базах: колонка, дописанная туда,
 *   существовала бы в бандле и больше нигде. Отдельный файл применяется и к
 *   существующим, и к свежим установкам — ровно поэтому существуют
 *   `2026_10_05_000200_constrain_carts_unique_to_active.php` и
 *   `2026_10_12_000100/000200/000300_add_*_status_check.php`.
 *
 * Зеркала: `nabilet_core_spec/migrations/016_login_logs_event.sql`,
 * консолидированные `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и у остальных CHECK: на движке, который CHECK не
 * исполняет, оператор молча получил бы необеспеченный инвариант, а
 * `migrate:status` показал бы «Ran».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED add_login_logs_event: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. `login_logs.event` is still added, but\n"
                . "    ck_login_logs_event is NOT enforced. migrate:status will still show\n"
                . "    this migration as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            $this->addColumn();

            return;
        }

        $this->addColumn();

        DB::statement(
            "ALTER TABLE login_logs ADD CONSTRAINT ck_login_logs_event "
            . "CHECK (event IN ('login','logout','login_failed'))"
        );
    }

    /**
     * Колонка — через Blueprint, ограничение — сырым DDL. Это не эстетика:
     * `tools/verify-migrations.php` собирает форму таблицы из объявлений
     * Blueprint, и колонка, добавленная `ALTER TABLE ... ADD COLUMN` строкой,
     * доезжает до рекордера без DEFAULT (сырой ADD COLUMN сворачивается в
     * `string $table->string('event', 32)`), после чего ратчет
     * «Core column defaults match» требует DEFAULT, который в миграции есть.
     * Проверено прогоном: именно на этом файл и падал. Ограничение Blueprint
     * выразить не может — этим занимается 014/015/017 по той же причине.
     *
     * Литералом, а не LoginLogEvent::LOGIN: миграция не тянет за собой
     * приложение - ни verify-migrations.php (он гоняет файлы без автолоадера,
     * и класс падает в фатал), ни сама база, которой словарь приложения знать
     * не обязательно. Владелец словаря - LoginLogEvent; он же процитирован
     * в CHECK ниже, и расхождение между ними ловит LoginLogEventTest.
     */
    private function addColumn(): void
    {
        if (Schema::hasColumn('login_logs', 'event')) {
            return;
        }

        Schema::table('login_logs', function (Blueprint $table): void {
            $table->string('event', 32)
                ->default('login')
                ->after('success');
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
        // Необратимо по замыслу: снятие инварианта ослабляет базу.
    }
};
