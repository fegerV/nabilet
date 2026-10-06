<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Инвариант «у зала не больше одной published и одной draft версии схемы» на
 * уровне БД.
 *
 * Зачем. Вся модель версионирования держится на этом инварианте, но до сих пор
 * его обеспечивал ТОЛЬКО код приложения:
 *
 *   • `HallRepository::publishSchemaVersion()` переводит прежнюю published-версию
 *     в `archived`, а `HallService` делает это под `lockForUpdate()` по залу;
 *   • редактор при загрузке берёт `versions.find(v => v.status === 'draft')` —
 *     то есть при двух черновиках молча открывает произвольный;
 *   • витрина продаёт по `currentSchemaVersion()` (`status = 'published'`), и при
 *     двух published выбор тоже произвольный.
 *
 * Любой обход сервиса (ручной UPDATE, импорт дампа, второй писатель, будущий
 * админ-скрипт) мог оставить зал с двумя живыми версиями — и зал начинал
 * продавать то одну схему, то другую. В MySQL нет частичных индексов, поэтому
 * инвариант выражен через генерируемые колонки: у «живой» строки туда попадает
 * `hall_id`, у остальных — NULL, а UNIQUE по колонке допускает сколько угодно
 * NULL, но ровно один не-NULL на зал.
 *
 * Fail-closed: если в базе УЖЕ есть нарушение, миграция падает с внятным
 * сообщением, а не «молча чинит» данные, удаляя чужие версии.
 */
return new class extends Migration
{
    private const PUBLISHED_COLUMN = 'published_hall_id';

    private const DRAFT_COLUMN = 'draft_hall_id';

    private const PUBLISHED_INDEX = 'uq_schema_one_published_per_hall';

    private const DRAFT_INDEX = 'uq_schema_one_draft_per_hall';

    public function up(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException(
                'Single-live-schema-version invariant requires MySQL/MariaDB generated columns.',
            );
        }

        $this->assertNoExistingViolations();

        if (!Schema::hasColumn('hall_schema_versions', self::PUBLISHED_COLUMN)) {
            DB::statement(
                'ALTER TABLE hall_schema_versions ADD COLUMN ' . self::PUBLISHED_COLUMN . ' BIGINT UNSIGNED'
                . " GENERATED ALWAYS AS (CASE WHEN status = 'published' THEN hall_id END) VIRTUAL",
            );
        }

        if (!Schema::hasColumn('hall_schema_versions', self::DRAFT_COLUMN)) {
            DB::statement(
                'ALTER TABLE hall_schema_versions ADD COLUMN ' . self::DRAFT_COLUMN . ' BIGINT UNSIGNED'
                . " GENERATED ALWAYS AS (CASE WHEN status = 'draft' THEN hall_id END) VIRTUAL",
            );
        }

        if (!$this->hasIndex(self::PUBLISHED_INDEX)) {
            DB::statement(
                'CREATE UNIQUE INDEX ' . self::PUBLISHED_INDEX
                . ' ON hall_schema_versions (' . self::PUBLISHED_COLUMN . ')',
            );
        }

        if (!$this->hasIndex(self::DRAFT_INDEX)) {
            DB::statement(
                'CREATE UNIQUE INDEX ' . self::DRAFT_INDEX
                . ' ON hall_schema_versions (' . self::DRAFT_COLUMN . ')',
            );
        }
    }

    public function down(): void
    {
        // Индексы и колонки удаляются без потери данных: они ничего не хранят,
        // а лишь выражают инвариант.
        foreach ([self::PUBLISHED_INDEX, self::DRAFT_INDEX] as $index) {
            if ($this->hasIndex($index)) {
                DB::statement('DROP INDEX ' . $index . ' ON hall_schema_versions');
            }
        }

        foreach ([self::PUBLISHED_COLUMN, self::DRAFT_COLUMN] as $column) {
            if (Schema::hasColumn('hall_schema_versions', $column)) {
                DB::statement('ALTER TABLE hall_schema_versions DROP COLUMN ' . $column);
            }
        }
    }

    /**
     * Проверить, что данные уже удовлетворяют инварианту.
     *
     * Молча «починить» это нельзя: выбрать, какую из двух published-версий
     * оставить, — решение владельца данных (вторая может быть той, по которой
     * уже проданы билеты).
     */
    private function assertNoExistingViolations(): void
    {
        foreach (['published', 'draft'] as $status) {
            $rows = DB::select(
                'SELECT hall_id, COUNT(*) AS c FROM hall_schema_versions'
                . ' WHERE status = ? GROUP BY hall_id HAVING c > 1 LIMIT 5',
                [$status],
            );

            if ($rows !== []) {
                $halls = implode(', ', array_map(static fn ($r) => (string) $r->hall_id, $rows));

                throw new \RuntimeException(
                    "Cannot enforce single {$status} schema version per hall: "
                    . "halls {$halls} already have more than one {$status} version. "
                    . 'Resolve them (archive the extra versions) before running this migration.',
                );
            }
        }
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['hall_schema_versions', $name],
        ) !== null;
    }
};
