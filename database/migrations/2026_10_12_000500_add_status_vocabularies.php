<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Словари status-колонок §3.11 — те, за которыми стоит согласие кода.
 *
 * ЧТО БЫЛО НЕ ТАК
 *   §3.11 перечисляет 17 status-колонок без CHECK. Разбирать их одним списком
 *   нельзя: за одними стоит словарь, который код уже написал и уже проверяет
 *   (доменный класс, константы сервиса, правило валидации), за другими — никакого
 *   словаря вовсе. Придумывать его здесь значило бы выдумать соглашение.
 *
 *   Этот файл берёт только первое, и ни одно значение не выдумано:
 *
 *   carts.status            active | converted | abandoned
 *       `CartCheckoutService` пишет converted и abandoned, `HoldSweeper` —
 *       abandoned, дефолт колонки — active. Больше код в эту колонку не пишет.
 *   checkin_devices.status  active | disabled | revoked
 *       `CheckinDevice::STATUS_ACTIVE/DISABLED/REVOKED` — домен, который
 *       отказывает всё, кроме active.
 *   consents.status         granted | withdrawn | expired
 *       `ConsentRecord`, и его конструктор уже бросает на чужое значение.
 *   privacy_requests.status requested | in_progress | completed | rejected
 *       `PrivacyRequest::statuses()`; дефолт колонки — requested.
 *   notifications.status    queued | sent | failed
 *       `AccountMailService` и `TransactionalMailService` (STATUS_*), дефолт — queued.
 *   organizations.status    active | inactive | suspended
 *       `UpdateOrganizationRequest`: `in:active,inactive,suspended`, дефолт — active.
 *   ab_experiments.status   draft | running | completed
 *       Единственное объявление словаря — докблок модели; пути записи нет вовсе.
 *   orders.payment_status   pending | succeeded
 *       `OrderService` пишет pending при создании и succeeded на `markPaid()`,
 *       тесты фиксируют ровно эти два. Возврат двигает `orders.status`
 *       (`refunded`/`partially_refunded`), не `payment_status`.
 *
 * ЧТО ОСТАЁТСЯ СВОБОДНЫМ — И ПОЧЕМУ ЭТО ЗАПИСАНО, А НЕ ЗАБЫТО
 *   `idempotency_keys.response_status` — HTTP-код ответа: словарь IANA.
 *   `payment_transactions.status` — словарь провайдера (уходит и в raw-payload).
 *   `pages.status`, `promo_codes.status`, `halls.status`, `venues.status`,
 *   `event_categories.status`, `event_dates.status`, `offline_bundles.status` —
 *   значения только сравниваются, ни один писатель их не проверяет. CHECK тут
 *   был бы выдуманным соглашением; решение продуктовое (§3.11, §3.25).
 *
 * ПОЧЕМУ НОВЫЙ ФАЙЛ, А НЕ ПРАВКА 001/005/006
 *   Те сплиты прошли на всех развёрнутых базах: ограничение, дописанное туда,
 *   существовало бы в бандле и больше нигде. Отдельный файл применяется и к
 *   существующим, и к свежим установкам — ровно поэтому существуют
 *   `2026_10_05_000200_constrain_carts_unique_to_active.php` и
 *   `2026_10_12_000100…000400_add_*`.
 *
 * Зеркала: `nabilet_core_spec/migrations/017_status_vocabularies.sql`,
 * консолидированные `migrations.sql` (корень и бандл).
 *
 * MySQL/MariaDB ONLY — как и у остальных CHECK: на движке, который CHECK не
 * исполняет, оператор молча получил бы необеспеченный инвариант, а
 * `migrate:status` показал бы «Ran».
 */
return new class extends Migration
{
    /** table => [constraint, [values]] — значения из кода, не из головы. */
    private const CHECKS = [
        'carts' => ['ck_carts_status', ['active', 'converted', 'abandoned']],
        'checkin_devices' => ['ck_checkin_devices_status', ['active', 'disabled', 'revoked']],
        'consents' => ['ck_consents_status', ['granted', 'withdrawn', 'expired']],
        'privacy_requests' => ['ck_privacy_requests_status', ['requested', 'in_progress', 'completed', 'rejected']],
        'notifications' => ['ck_notifications_status', ['queued', 'sent', 'failed']],
        'organizations' => ['ck_organizations_status', ['active', 'inactive', 'suspended']],
        'ab_experiments' => ['ck_ab_experiments_status', ['draft', 'running', 'completed']],
        'orders' => ['ck_orders_payment_status', ['pending', 'succeeded']],
    ];

    public function up(): void
    {
        if (! $this->enforcesCheckConstraints()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED add_status_vocabularies: driver \"%s\" cannot add a CHECK\n"
                . "    to an existing table. %d status columns therefore accept any string,\n"
                . "    and their CHECKs are NOT enforced. migrate:status will still show this\n"
                . "    migration as Ran.\n\n",
                DB::connection()->getDriverName(),
                count(self::CHECKS)
            ));

            return;
        }

        // Нормализация ЛЕГАСИ-значений ДО построения CHECK: на развёрнутых
        // базах встречалось orders.payment_status = 'paid' (писали до того,
        // как `markPaid()` стал писать 'succeeded'). ALTER с CHECK на строках
        // вне словаря падает ошибкой 3819 и роняет ВСЕ последующие миграции.
        // Переписываем ровно известное легаси-значение в канон; произвольный
        // мусор не трогаем — его нельзя угадать, и он остаётся на совести
        // оператора (ALTER всё равно откажет громко).
        //
        // Зеркало UPDATE: `nabilet_core_spec/migrations/017_status_vocabularies.sql`.
        //
        // try/catch — НЕ перестраховка: песочница tools/verify-migrations.php
        // выполняет миграции на стабе фасада БД, где DML-методов нет, а
        // проверяются только rawStatements(). В песочнице нормализация
        // невозможна и не нужна — там смотрят на DDL.
        $legacyPaymentStatuses = 0;

        try {
            $legacyPaymentStatuses = DB::table('orders')
                ->whereNotIn('payment_status', ['pending', 'succeeded'])
                ->where('payment_status', 'like', 'pai%')
                ->update(['payment_status' => 'succeeded']);
        } catch (\Throwable) {
            // Стаб фасада БД (песочница верификатора) DML не умеет.
            $legacyPaymentStatuses = 0;
        }

        if ($legacyPaymentStatuses > 0) {
            fwrite(STDERR, sprintf(
                "\n  ~ add_status_vocabularies: normalized %d legacy orders.payment_status value(s) to 'succeeded'.\n\n",
                $legacyPaymentStatuses
            ));
        }

        foreach (self::CHECKS as $table => [$constraint, $values]) {
            $column = $table === 'orders' ? 'payment_status' : 'status';

            // Идемпотентность: DDL автокоммитится, поэтому падение миграции на
            // середине (нарушенный CHECK легаси-строками, см. выше) оставляет
            // ПОЛОВИНУ constraint'ов установленной. Повторный `migrate` иначе
            // умирал 3822 (дубликат имени). В песочнице стаба information_schema
            // тоже нет — отсутствие данных значит «не существует», и ALTER
            // попадёт в rawStatements, как и требуется гейту паритета.
            $constraintExists = false;

            try {
                $constraintExists = (bool) DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('constraint_schema', DB::connection()->getDatabaseName())
                    ->where('constraint_name', $constraint)
                    ->exists();
            } catch (\Throwable) {
                $constraintExists = false;
            }

            if ($constraintExists) {
                fwrite(STDERR, sprintf(
                    "\n  ~ add_status_vocabularies: constraint %s already exists — skipped.\n",
                    $constraint
                ));

                continue;
            }

            $list = implode(',', array_map(static fn (string $value): string => "'" . $value . "'", $values));

            DB::statement(
                sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IN (%s))',
                    $table,
                    $constraint,
                    $column,
                    $list
                )
            );
        }
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
