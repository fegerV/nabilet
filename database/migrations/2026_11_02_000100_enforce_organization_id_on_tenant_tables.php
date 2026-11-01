<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 3 мультитенантности: `organization_id` обязателен и ссылается на
 * `organizations`.
 *
 * ПРЕДУСЛОВИЕ. Перед применением на боевой базе выполните
 * `php artisan tenancy:backfill-organization` и убедитесь, что отчёт говорит
 * `null_org = 0` для всех таблиц. Миграция намеренно НЕ угадывает организацию:
 * строка без арендатора означает ошибку данных, а не повод выбрать первую
 * попавшуюся.
 *
 * ЧТО ДЕЛАЕТ. Для 40 таблиц класса A:
 *   1. делает `organization_id` NOT NULL;
 *   2. добавляет FK на `organizations(id)` с каскадным удалением.
 *
 * Каскад согласован с уже существующими дочерними таблицами (`events`,
 * `venues`, `orders` держат RESTRICT на своих уровнях, поэтому удаление
 * организации с заказами всё равно будет отклонено; каскад срабатывает только
 * для данных организации, которую действительно можно удалить).
 *
 * Список совпадает с миграцией фазы 1
 * (`2026_10_29_000100_add_organization_id_to_tenant_tables.php`). Обе миграции
 * исторические: одна добавляет nullable-колонку, эта — доводит её до
 * обязательной.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'sessions',
        'inventory_items',
        'carts',
        'cart_items',
        'seat_holds',
        'order_items',
        'payments',
        'payment_transactions',
        'refunds',
        'tickets',
        'ticket_scans',
        'halls',
        'hall_schema_versions',
        'sectors',
        'hall_rows',
        'seats',
        'hall_tables',
        'standing_zones',
        'event_translations',
        'venue_translations',
        'page_translations',
        'promo_code_redemptions',
        'event_dates',
        'event_speakers',
        'event_sponsors',
        'event_faqs',
        'event_artists',
        'event_schedule_items',
        'artist_event',
        'ab_variants',
        'ab_assignments',
        'ab_metrics',
        'webhook_deliveries',
        'event_favorites',
        'event_follows',
        'follow_notifications',
        'raffles',
        'raffle_prizes',
        'raffle_entries',
        'raffle_winners',
    ];

    public function up(): void
    {
        // MySQL выполняет DDL с неявным COMMIT: если миграция упадёт на
        // двадцатой таблице, первые девятнадцать останутся изменёнными, а
        // запись о миграции не появится. Повторный запуск обязан быть
        // безопасным, поэтому каждая операция проверяет текущее состояние.
        foreach (self::TABLES as $table) {
            $nulls = DB::table($table)->whereNull('organization_id')->count();

            if ($nulls > 0) {
                throw new \RuntimeException(sprintf(
                    'Table %s has %d row(s) without organization_id. Run tenancy:backfill-organization first.',
                    $table,
                    $nulls,
                ));
            }

            $column = (array) collect(Schema::getColumns($table))
                ->firstWhere('name', 'organization_id');

            if (($column['nullable'] ?? false) === true) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->unsignedBigInteger('organization_id')->nullable(false)->change();
                });
            }
        }

        foreach (self::TABLES as $table) {
            $constraint = 'fk_' . $table . '_org';

            $exists = collect(Schema::getForeignKeys($table))->contains(
                static fn (array $fk): bool => (string) ($fk['name'] ?? '') === $constraint,
            );

            if ($exists) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($constraint): void {
                $t->foreign('organization_id', $constraint)
                    ->references('id')
                    ->on('organizations')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $constraint = 'fk_' . $table . '_org';

            $exists = collect(Schema::getForeignKeys($table))->contains(
                static fn (array $fk): bool => (string) ($fk['name'] ?? '') === $constraint,
            );

            if (! $exists) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($constraint): void {
                $t->dropForeign($constraint);
            });
        }

        foreach (self::TABLES as $table) {
            $column = (array) collect(Schema::getColumns($table))
                ->firstWhere('name', 'organization_id');

            if (($column['nullable'] ?? true) === false) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->unsignedBigInteger('organization_id')->nullable()->change();
                });
            }
        }
    }
};
