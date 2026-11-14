<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Замыкание дефектов §3.15 (REVIEW-spec-bundle.md) — инварианты 152-ФЗ.
 *
 * consents (согласие субъекта):
 *   - `withdrawn_at` — момент, когда согласие перестало действовать. Раньше
 *     его некуда было записать: таблица несёт только `created_at`, а строка
 *     со статусом `withdrawn` отвечала на «отозвано?», но не на «когда?» —
 *     ровно тот класс дефекта, что `tickets.revoked_at` (§3.11). Журнальная
 *     модель сохранена: отзыв пишет НОВУЮ строку (см.
 *     `NewsletterSubscriptionService::unsubscribeByToken()`), и она несёт
 *     момент отзыва в себе.
 *   - `ck_consents_withdrawn_has_moment`: строку `withdrawn` без записанного
 *     момента отзыва база отвергает — «отозвано, но неизвестно когда» больше
 *     не является легальным состоянием.
 *
 *   ЧТО РЕЦЕПТ §3.15 ТРЕБОВАЛ, НО ЧЕГО БАЗА НЕ МОЖЕТ. CHECK «ровно один
 *   субъект» — `(user_id IS NOT NULL) <> (anonymous_id IS NOT NULL)` — MySQL
 *   8.4 отвергает ошибкой 3823: колонка `user_id` нужна referential action'у
 *   `fk_consents_user … ON DELETE SET NULL`, а колонки, на которые ссылается
 *   referential action, в CHECK использовать нельзя. Триггер не выход: MySQL
 *   не активирует триггеры для FK-действий, то есть «ничейные» строки,
 *   порождённые самим `SET NULL`, триггер не увидит. Инвариант остаётся
 *   доменным — `ConsentRecord` отвергает и «никого», и «обоих»
 *   (INVALID_CONSENT_SUBJECT); сделать его базовым можно только сменой
 *   семантики FK (RESTRICT + ручное разъединение при erasure), это отдельное
 *   решение и отдельный шаг.
 *
 * privacy_requests (обращение субъекта):
 *   - `due_at` — записанный срок ответа. Домен считает период параметром
 *     (`PrivacyRequest::dueAt(int $slaDays)` — ни ТЗ, ни контракт не фиксируют
 *     число, выдумывать его здесь запрещено), но число, вычисленное создателем
 *     строки, обязано иметь склеп: срок, который нельзя записать, нельзя и
 *     проконтролировать. Колонка NOT NULL: обращение рождается уже с
 *     обязательством ответить в срок, «обращение без дедлайна» — не легальное
 *     состояние.
 *   - `ck_privacy_requests_type` — словарь пяти видов обращений из домена
 *     (`PrivacyRequest::types()`); по образцу миграции 017 барьер становится
 *     двойным — класс и база не могут разойтись, значения берутся из констант.
 *
 * Пустые таблицы проверены перед написанием: `consents` и `privacy_requests`
 * содержат 0 строк, поэтому NOT NULL `due_at` не требует бэкфилла.
 *
 * Зеркала: `nabilet_core_spec/migrations/026_privacy_guards.sql`,
 * консолидированные `migrations.sql` (корень и `nabilet_core_spec/`).
 *
 * MySQL/MariaDB ONLY — как и остальные CHECK (см. миграцию 017): движок,
 * который CHECK не исполняет, молча получил бы необеспеченный инвариант, а
 * `migrate:status` показал бы «Ran».
 */
return new class extends Migration
{
    public function up(): void
    {
        // Колонки — на любом драйвере; идемпотентно: DDL автокоммитится,
        // поэтому повторный `migrate` после падения на середине обязан быть
        // безопасным (паттерн миграции 2026_11_02_000100).
        $consentColumn = (array) collect(Schema::getColumns('consents'))
            ->firstWhere('name', 'withdrawn_at');

        if ($consentColumn === []) {
            Schema::table('consents', static function (Blueprint $t): void {
                $t->dateTime('withdrawn_at', 6)->nullable()->after('status');
            });
        }

        $requestColumn = (array) collect(Schema::getColumns('privacy_requests'))
            ->firstWhere('name', 'due_at');

        if ($requestColumn === []) {
            Schema::table('privacy_requests', static function (Blueprint $t): void {
                $t->dateTime('due_at', 6)->nullable(false)->after('status');
            });
        }

        // CHECK-инварианты — только там, где движок их действительно исполняет.
        if (! $this->enforcesCheckConstraints()) {
            $this->warn(sprintf(
                "\n  ! SKIPPED privacy CHECK constraints: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. The consent/request invariants are NOT enforced\n"
                . "    on this driver; `migrate:status` will still show this migration as Ran.\n\n",
                DB::connection()->getDriverName()
            ));

            return;
        }

        // [таблица, имя, SQL]. Идемпотентность: DDL падает 3822 на дубликате
        // имени, а падение на середине оставляет ПОЛОВИНУ constraint'ов
        // установленной — поэтому перед каждым ALTER спросим information_schema.
        $constraints = [
            [
                'consents',
                'ck_consents_withdrawn_has_moment',
                "ALTER TABLE consents ADD CONSTRAINT ck_consents_withdrawn_has_moment CHECK (status <> 'withdrawn' OR withdrawn_at IS NOT NULL)",
            ],
            [
                'privacy_requests',
                'ck_privacy_requests_type',
                "ALTER TABLE privacy_requests ADD CONSTRAINT ck_privacy_requests_type CHECK (type IN ('access','erasure','rectification','restriction','portability'))",
            ],
        ];

        foreach ($constraints as [$table, $constraint, $sql]) {
            $constraintExists = false;

            try {
                $constraintExists = (bool) DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('constraint_schema', DB::connection()->getDatabaseName())
                    ->where('constraint_name', $constraint)
                    ->exists();
            } catch (\Throwable) {
                // Песочница верификатора стаба information_schema не умеет:
                // отсутствие данных значит «не существует», ALTER попадёт в
                // rawStatements, как и требуется гейту паритета.
                $constraintExists = false;
            }

            if ($constraintExists) {
                $this->warn(sprintf(
                    "\n  ~ privacy_guards: constraint %s already exists — skipped.\n",
                    $constraint
                ));

                continue;
            }

            DB::statement($sql);
        }
    }

    /**
     * Драйверы, которые действительно исполняют CHECK на существующей таблице
     * (MySQL 8.0.16+, MariaDB 10.2.1+) — см. миграцию 017.
     */
    private function enforcesCheckConstraints(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Предупреждение о пропущенных CHECK-инвариантах.
     *
     * STDERR существует только в CLI: под mod_cgi/FPM на шаред-хостинге
     * константы STDERR нет, и fwrite(STDERR, ...) ронял установку
     * фатальной ошибкой «Undefined constant STDERR» (TimeWeb, 2026-11-14,
     * найдено визардом v1.1.0). В веб-контексте предупреждение уходит в
     * журнал с префиксом «Installer:» — его показывает /install/log.
     */
    private function warn(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message);

            return;
        }

        try {
            \Illuminate\Support\Facades\Log::warning('Installer: ' . rtrim($message));
        } catch (\Throwable) {
            // нет журнала — не блокируем миграцию
        }
    }
    public function down(): void
    {
        // Необратимо по замыслу (см. миграцию 017): снятие инварианта 152-ФЗ
        // ослабляет базу; DROP колонок, на которых стоят CHECK, и невозможен.
    }
};
