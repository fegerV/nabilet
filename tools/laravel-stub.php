<?php

declare(strict_types=1);

/**
 * Minimal Laravel Schema/Migration stubs — verification tooling only.
 *
 * PURPOSE: this sandbox cannot run `composer install` (PHP network access is
 * blocked), so `php artisan migrate` is unavailable. Rather than leave the schema
 * unverified, these stubs implement enough of Laravel's migration API to RECORD
 * what each migration declares, and then emit SQLite DDL.
 *
 * The point is that the migrations themselves are executed — their real code, their
 * real chains of calls. If a migration references a table that does not exist yet,
 * declares a duplicate index name, or misuses a column type, this catches it.
 *
 * WHAT IT DOES NOT PROVE:
 *   - MySQL-specific behaviour (engine, collation, index prefix limits, the
 *     191-char index budget). MySQL remains the deployment target.
 *   - Eloquent models, observers, application-level immutability guards.
 * Those must be covered by the real test suite once Composer is available.
 *
 * This file is NOT autoloaded by the application and ships only in tools/.
 */

namespace Illuminate\Database\Migrations {
    abstract class Migration
    {
        abstract public function up(): void;

        abstract public function down(): void;
    }
}

namespace Illuminate\Database\Schema {
    /**
     * A column declaration. Every modifier returns $this so Laravel's fluent chains
     * work unchanged.
     */
    class ColumnDefinition
    {
        /** @var list<string> */
        public array $modifiers = [];

        public ?string $default = null;
        public bool $hasDefault = false;
        public bool $isNullable = false;
        public ?string $comment = null;
        public bool $autoIncrement = false;
        public bool $isPrimary = false;

        /**
         * `->change()`: переписать уже существующую колонку, а не добавить.
         *
         * Миграции используют это, чтобы превращать nullable-колонку в NOT NULL
         * (фаза 3 мультитенантности: `->nullable(false)->change()`). Рекордер
         * применяет замену при фолдинге ALTER в таблицу — без флага колонка
         * дублировалась бы (nullable и NOT NULL одновременно), и сверка с
         * бандлом видела бы две колонки там, где в схеме одна.
         */
        public bool $isModified = false;

        /** @var list<array{type: string, name: string|null}> */
        public array $indexes = [];

        /** @var list<string> */
        public array $enumValues = [];

        /**
         * The blueprint that owns this column. Needed so that
         * foreignId('x')->constrained('y') can register its foreign key on the
         * table — without this reference the FK would be created and immediately
         * discarded, silently producing a schema with no referential integrity.
         */
        public ?Blueprint $blueprint = null;

        public function __construct(
            public string $type,
            public string $name,
            public ?int $length = null,
            public ?int $precision = null,
            public ?int $scale = null,
        ) {
        }

        public function nullable(bool $value = true): self
        {
            $this->isNullable = $value;

            return $this;
        }

        /** `->change()`: заменить объявление уже существующей колонки. */
        public function change(): self
        {
            $this->isModified = true;

            return $this;
        }

        public function default(mixed $value): self
        {
            $this->hasDefault = true;
            $this->default = match (true) {
                $value === null => 'NULL',
                $value === true => '1',
                $value === false => '0',
                is_int($value), is_float($value) => (string) $value,
                default => "'" . str_replace("'", "''", (string) $value) . "'",
            };

            return $this;
        }

        public function comment(string $text): self
        {
            $this->comment = $text;

            return $this;
        }

        public function unique(?string $name = null): self
        {
            $this->indexes[] = [
                'type' => 'unique',
                'name' => $name ?? $this->autoIndexName('unique'),
            ];

            return $this;
        }

        public function index(?string $name = null): self
        {
            $this->indexes[] = [
                'type' => 'index',
                'name' => $name ?? $this->autoIndexName('index'),
            ];

            return $this;
        }

        /**
         * The name Laravel would give an index on this single column.
         *
         * A column-level `->index()` has no columns argument, so the name can only
         * be built from the owning blueprint's table plus the column. Without the
         * blueprint reference there is nothing to name it after; `''` is returned
         * in that (unreachable in practice) case so the caller still gets a string.
         */
        private function autoIndexName(string $type): string
        {
            return Blueprint::autoName($this->blueprint?->table ?? '', [$this->name], $type);
        }

        public function primary(): self
        {
            $this->isPrimary = true;

            return $this;
        }

        public function autoIncrement(): self
        {
            $this->autoIncrement = true;

            return $this;
        }

        public function useCurrent(): self
        {
            $this->hasDefault = true;
            $this->default = 'CURRENT_TIMESTAMP';

            return $this;
        }

        /** FK declared inline, e.g. foreignId('x')->constrained('y') */
        public function constrained(?string $table = null, ?string $column = 'id'): ForeignKeyDefinition
        {
            $table ??= $this->name;
            $fk = new ForeignKeyDefinition(
                $this->name,
                $table,
                $column ?? 'id',
                // Laravel names this constraint `<table>_<column>_foreign`. Recording
                // NULL instead would drop the FK out of the migration/spec diff.
                Blueprint::autoName($this->blueprint?->table ?? '', [$this->name], 'foreign'),
            );

            // Register on the owning table — see the $blueprint property docblock.
            if ($this->blueprint !== null) {
                $this->blueprint->foreignKeys[] = $fk;
            }

            return $fk;
        }

        /** No-op modifiers that only affect MySQL storage. */
        public function restrictOnDelete(): self
        {
            return $this;
        }

        public function cascadeOnDelete(): self
        {
            return $this;
        }

        public function nullOnDelete(): self
        {
            return $this;
        }

        public function after(string $column): self
        {
            return $this;
        }

        public function charset(string $charset): self
        {
            return $this;
        }

        public function collation(string $collation): self
        {
            return $this;
        }

        public function storedAs(string $expression): self
        {
            return $this;
        }
    }

    class ForeignKeyDefinition
    {
        public ?string $references = null;
        public string $onDelete = 'NO ACTION';
        public bool $constrained = true;
        public ?string $comment = null;

        public function __construct(
            public string $column,
            public string $table,
            public string $onColumn = 'id',
            public ?string $name = null,
        ) {
            $this->references = $onColumn;
        }

        public function comment(string $text): self
        {
            $this->comment = $text;

            return $this;
        }

        public function references(string $column): self
        {
            $this->references = $column;

            return $this;
        }

        public function on(string $table): self
        {
            $this->table = $table;

            return $this;
        }

        public function restrictOnDelete(): self
        {
            $this->onDelete = 'RESTRICT';

            return $this;
        }

        public function cascadeOnDelete(): self
        {
            $this->onDelete = 'CASCADE';

            return $this;
        }

        public function nullOnDelete(): self
        {
            $this->onDelete = 'SET NULL';

            return $this;
        }

        public function onDelete(string $action): self
        {
            $this->onDelete = strtoupper($action);

            return $this;
        }

        public function onUpdate(string $action): self
        {
            return $this;
        }
    }

    /**
     * Records a migration's declarations for one table.
     */
    class Blueprint
    {
        /** @var list<ColumnDefinition> */
        public array $columns = [];

        /** @var list<ForeignKeyDefinition> */
        public array $foreignKeys = [];

        /** @var list<array{columns: list<string>, type: string, name: string|null}> */
        public array $indexes = [];

        /** @var list<string> */
        public array $primary = [];

        /** @var list<string> */
        public array $drops = [];

        public function __construct(public string $table)
        {
        }

        /**
         * Laravel's own name for an index it was not given a name for.
         *
         * Mirrors `Blueprint::createIndexName()`: `{table}_{col}_{col}_{type}`,
         * lower-cased, with `-` and `.` folded to `_`.
         *
         * Synthesising it here is load-bearing. Without it, `$table->index(['a',
         * 'b'])` records a NULL name, and because the migration/spec diff compares
         * index NAMES, such an index was invisible to the gate — it could not be
         * reported as missing, extra or duplicated. Every unnamed index and
         * foreign key in the migration set was silently unchecked.
         *
         * @param list<string> $columns
         */
        public static function autoName(string $table, array $columns, string $type): string
        {
            return str_replace(['-', '.'], '_', strtolower($table . '_' . implode('_', $columns) . '_' . $type));
        }

        public function id(string $column = 'id'): ColumnDefinition
        {
            $col = new ColumnDefinition('id', $column);
            $col->autoIncrement = true;
            $col->isPrimary = true;
            $this->columns[] = $col;

            return $col;
        }

        public function string(string $column, ?int $length = 255): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('string', $column, $length));
        }

        public function char(string $column, ?int $length = 255): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('string', $column, $length));
        }

        public function text(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('text', $column));
        }

        public function longText(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('text', $column));
        }

        public function mediumText(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('text', $column));
        }

        public function binary(string $column, ?int $length = null): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('binary', $column, $length));
        }

        public function uuid(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('string', $column, 36));
        }

        public function integer(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function bigInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function unsignedInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function unsignedBigInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function smallInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function mediumInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function unsignedSmallInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function unsignedTinyInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function tinyInteger(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function boolean(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('boolean', $column));
        }

        public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('decimal', $column, null, $precision, $scale));
        }

        public function json(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('json', $column));
        }

        /** @param list<string> $values */
        public function enum(string $column, array $values): ColumnDefinition
        {
            $col = new ColumnDefinition('enum', $column);
            $col->enumValues = $values;

            return $this->add($col);
        }

        public function timestamp(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('datetime', $column));
        }

        public function dateTime(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('datetime', $column));
        }

        public function time(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('time', $column));
        }

        public function date(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('datetime', $column));
        }

        public function foreignId(string $column): ColumnDefinition
        {
            return $this->add(new ColumnDefinition('integer', $column));
        }

        public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
        {
            $column = is_array($columns) ? $columns[0] : $columns;

            // The explicit constraint name matters: the spec names every FK, and
            // verify-migrations.php diffs those names. Dropping it here would make
            // the diff silently vacuous. When the migration gives no name, Laravel
            // generates one — reproduce that rather than recording NULL, or the FK
            // disappears from the diff entirely.
            $fk = new ForeignKeyDefinition(
                $column,
                '',
                'id',
                $name ?? self::autoName($this->table, [$column], 'foreign'),
            );
            $this->foreignKeys[] = $fk;

            return $fk;
        }

        public function timestamps(): void
        {
            $this->timestamp('created_at')->nullable();
            $this->timestamp('updated_at')->nullable();
        }

        public function softDeletes(string $column = 'deleted_at'): void
        {
            $this->timestamp($column)->nullable();
        }

        /**
         * Laravel's rememberToken() is a shorthand for a nullable 100-char string.
         * Without it here, verify-migrations.php died with an uncaught
         * BadMethodCallException on database/migrations/
         * 2026_09_22_001300_add_remember_token.php — the whole gate exited 255, so
         * every later migration went unchecked. Note: this column is NOT in the
         * spec bundle; see docs/REVIEW-spec-bundle.md.
         */
        public function rememberToken(): ColumnDefinition
        {
            return $this->string('remember_token', 100)->nullable();
        }

        /** @param list<string>|string $columns */
        public function unique(array|string $columns, ?string $name = null): void
        {
            $columns = is_array($columns) ? array_values($columns) : [$columns];

            $this->indexes[] = [
                'columns' => $columns,
                'type' => 'unique',
                'name' => $name ?? self::autoName($this->table, $columns, 'unique'),
            ];
        }

        /** @param list<string>|string $columns */
        public function index(array|string $columns, ?string $name = null): void
        {
            $columns = is_array($columns) ? array_values($columns) : [$columns];

            $this->indexes[] = [
                'columns' => $columns,
                'type' => 'index',
                'name' => $name ?? self::autoName($this->table, $columns, 'index'),
            ];
        }

        /** @param list<string>|string $columns */
        public function primary(array|string $columns): void
        {
            $this->primary = is_array($columns) ? array_values($columns) : [$columns];
        }

        public function dropForeign(string|array $columns): void
        {
            $this->drops[] = is_array($columns) ? $columns[0] : $columns;
        }

        public function dropColumn(string|array $columns): void
        {
            $this->drops[] = is_array($columns) ? $columns[0] : $columns;
        }

        public function dropIndex(string|array $index): void
        {
            $this->drops[] = is_array($index) ? $index[0] : $index;
        }

        public function dropUnique(string|array $index): void
        {
            $this->drops[] = is_array($index) ? $index[0] : $index;
        }

        /** Laravel's morphs(): an unsigned big integer + a string type column. */
        public function morphs(string $name): void
        {
            $this->unsignedBigInteger("{$name}_id");
            $this->string("{$name}_type");
        }

        public function nullableMorphs(string $name): void
        {
            $this->morphs($name);
        }

        private function add(ColumnDefinition $column): ColumnDefinition
        {
            $column->blueprint = $this;
            $this->columns[] = $column;

            return $column;
        }
    }

    /**
     * The recorded schema across all migrations, plus DDL generation.
     */
    class SchemaRecorder
    {
        /** @var array<string, Blueprint> */
        public array $tables = [];

        /** @var list<string> */
        public array $operations = [];

        /** @var array<string, list<ForeignKeyDefinition>> FKs added after creation */
        public array $addedForeignKeys = [];

        public function create(string $table, callable $callback): void
        {
            $blueprint = new Blueprint($table);
            $callback($blueprint);
            $this->tables[$table] = $blueprint;
            $this->operations[] = 'create:' . $table;
        }

        public function table(string $table, callable $callback): void
        {
            if (! isset($this->tables[$table])) {
                throw new RuntimeException(
                    sprintf('Schema::table("%s") — no such table has been created yet', $table)
                );
            }

            $blueprint = new Blueprint($table);
            $callback($blueprint);

            $existing = $this->tables[$table];

            // ALTER additions must be folded back into the recorded table. The
            // original implementation discarded them, so every column/index added by
            // Schema::table() — the checkout migrations, the TZ-gap closure (010),
            // and any later migration — was invisible to verify-migrations.php and
            // reported as drift ("cart_token missing from migrations", etc.).
            foreach ($blueprint->columns as $col) {
                if ($col->isModified) {
                    // `->change()` переписывает существующую колонку: заменяем
                    // объявление по имени, а не добавляем вторую. Индексы старой
                    // колонки сохраняются, если новое объявление своих не несёт.
                    $replaced = false;
                    foreach ($existing->columns as $i => $old) {
                        if ($old->name !== $col->name) {
                            continue;
                        }
                        $col->indexes = $col->indexes !== [] ? $col->indexes : $old->indexes;
                        $existing->columns[$i] = $col;
                        $replaced = true;

                        break;
                    }
                    if (! $replaced) {
                        $existing->columns[] = $col;
                    }

                    continue;
                }

                $existing->columns[] = $col;
            }

            foreach ($blueprint->indexes as $idx) {
                $existing->indexes[] = $idx;
            }

            // Process DROP COLUMN / DROP INDEX / DROP UNIQUE / DROP FOREIGN so the
            // verifier sees the post-ALTER shape, not only the additions.
            foreach ($blueprint->drops as $name) {
                $existing->columns = array_values(array_filter(
                    $existing->columns,
                    static fn (ColumnDefinition $c): bool => $c->name !== $name
                ));
                $existing->indexes = array_values(array_filter(
                    $existing->indexes,
                    static fn (array $i): bool => ($i['name'] ?? null) !== $name
                ));
                $existing->foreignKeys = array_values(array_filter(
                    $existing->foreignKeys,
                    static fn (ForeignKeyDefinition $f): bool => $f->name !== $name
                ));

                // Also drop any column-level index that referenced the dropped name.
                foreach ($existing->columns as $column) {
                    $column->indexes = array_values(array_filter(
                        $column->indexes,
                        static fn (array $index): bool => ($index['name'] ?? null) !== $name
                    ));
                }
            }

            foreach ($blueprint->foreignKeys as $fk) {
                $this->addedForeignKeys[$table][] = $fk;
            }

            $this->operations[] = 'alter:' . $table;
        }

        public function dropIfExists(string $table): void
        {
            unset($this->tables[$table]);
            $this->operations[] = 'drop:' . $table;
        }

        public function hasTable(string $table): bool
        {
            return isset($this->tables[$table]);
        }

        public function hasColumn(string $table, string $column): bool
        {
            foreach ($this->tables[$table]->columns ?? [] as $col) {
                if ($col->name === $column) {
                    return true;
                }
            }

            return false;
        }
    }

    class Schema
    {
        private static ?SchemaRecorder $recorder = null;

        private static ?RawStatements $rawStatements = null;

        public static function recorder(): SchemaRecorder
        {
            return self::$recorder ??= new SchemaRecorder();
        }

        public static function reset(): void
        {
            self::$recorder = new SchemaRecorder();
        }

        public static function create(string $table, callable $callback): void
        {
            self::recorder()->create($table, $callback);
        }

        public static function table(string $table, callable $callback): void
        {
            self::recorder()->table($table, $callback);
        }

        public static function dropIfExists(string $table): void
        {
            self::recorder()->dropIfExists($table);
        }

        public static function hasTable(string $table): bool
        {
            return self::recorder()->hasTable($table);
        }

        public static function hasColumn(string $table, string $column): bool
        {
            return self::recorder()->hasColumn($table, $column);
        }

        /**
         * Stand-in for Schema::getColumns(). Migrations read the CURRENT shape of
         * a column to decide whether DDL is needed at all — the tenant-enforcement
         * migration changes `organization_id` to NOT NULL only when it still is
         * nullable. The shape mirrors what information_schema reports: `name`,
         * `nullable`, `default`. Unknown table is an error, same as live.
         *
         * @return list<array{name: string, nullable: bool, default: mixed}>
         */
        public static function getColumns(string $table): array
        {
            $blueprint = self::recorder()->tables[$table] ?? null;
            if ($blueprint === null) {
                throw new \RuntimeException(sprintf('Schema::getColumns("%s") — no such table has been created yet', $table));
            }

            $out = [];
            foreach ($blueprint->columns as $col) {
                $out[] = [
                    'name' => $col->name,
                    'nullable' => $col->isNullable,
                    'default' => $col->hasDefault ? $col->default : null,
                ];
            }

            return $out;
        }

        /**
         * Stand-in for Schema::getForeignKeys(). The tenant-enforcement migration
         * adds `fk_<table>_org` only when it is absent, so an idempotent rerun
         * must see the keys previous operations recorded. Both sources of keys
         * are consulted: inline (column-level) and added by Schema::table calls.
         *
         * @return list<array{name: ?string, columns: list<string>, references_table: ?string}>
         */
        public static function getForeignKeys(string $table): array
        {
            $blueprint = self::recorder()->tables[$table] ?? null;
            if ($blueprint === null) {
                throw new \RuntimeException(sprintf('Schema::getForeignKeys("%s") — no such table has been created yet', $table));
            }

            $out = [];
            foreach ($blueprint->foreignKeys as $fk) {
                $out[] = ['name' => $fk->name, 'columns' => [$fk->column], 'references_table' => $fk->table];
            }
            foreach (self::recorder()->addedForeignKeys[$table] ?? [] as $fk) {
                $out[] = ['name' => $fk->name, 'columns' => [$fk->column], 'references_table' => $fk->table];
            }

            return $out;
        }

        public static function rawStatements(): RawStatements
        {
            return self::$rawStatements ??= new RawStatements();
        }
    }

    /**
     * Collects raw SQL issued through the DB facade (CHECK constraints, triggers).
     *
     * The sandbox has no database, so `statement()`/`unprepared()` cannot prove the
     * SQL runs. What they DO prove is that the migration reached them, and the
     * recorder lets verify-migrations.php diff the emitted DDL against the spec.
     */
    class RawStatements
    {
        /** @var list<string> */
        public array $statements = [];

        public function add(string $sql): void
        {
            $this->statements[] = $sql;
        }
    }

    /**
     * Stand-in for Illuminate\Database\Connection. The driver name is what the
     * MySQL-only migrations guard on, so it defaults to `mysql`.
     */
    class Connection
    {
        public function getDriverName(): string
        {
            return getenv('NABILET_STUB_DRIVER') ?: 'mysql';
        }
    }
}

namespace {
    /**
     * Stand-in for the global collect() helper (its home is the GLOBAL namespace:
     * migrations call `collect(Schema::getColumns(...))` unqualified). The sandbox
     * does not load the vendor helpers file. Only the two methods the migrations
     * actually call are implemented on the Collection; anything else should be
     * added with a comment naming the migration that needs it.
     */
    if (! function_exists('collect')) {
        function collect($items = []): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection((array) $items);
        }
    }
}

namespace Illuminate\Support {
    /**
     * Мини-копия `Illuminate\Support\Collection` — только то, что вызывают
     * миграции из песочницы: `firstWhere` (поиск колонки по имени) и
     * `contains` (проверка существования FK по имени).
     */
    class Collection
    {
        public function __construct(private /* readonly */ array $items = [])
        {
        }

        public function firstWhere(string $key, mixed $value): array|object|null
        {
            foreach ($this->items as $item) {
                $arr = (array) $item;
                if (($arr[$key] ?? null) === $value) {
                    return $item;
                }
            }

            return null;
        }

        /**
         * @param callable(mixed): bool $callback
         */
        public function contains(callable $callback): bool
        {
            foreach ($this->items as $item) {
                if ($callback($item)) {
                    return true;
                }
            }

            return false;
        }

        public function all(): array
        {
            return $this->items;
        }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function statement(string $sql): bool
        {
            \Illuminate\Database\Schema\Schema::rawStatements()->add($sql);

            return true;
        }

        public static function unprepared(string $sql): bool
        {
            \Illuminate\Database\Schema\Schema::rawStatements()->add($sql);

            return true;
        }

        public static function connection(?string $name = null): \Illuminate\Database\Schema\Connection
        {
            return new \Illuminate\Database\Schema\Connection();
        }

        /**
         * Stand-in for DB::selectOne(). Migrations use it for probes like
         * `select version()`; the stub returns a generic stdClass so property
         * access (->version, ->c) works without a live database.
         *
         * "Not found" is NULL, not false — that is what the real connection
         * returns (`array_shift()` of an empty result). It matters: a migration
         * that probes with `DB::selectOne(...) !== null` treats `false` as
         * "a row was found", so returning false made `hasIndex()` report every
         * index as existing and silently skipped its CREATE.
         */
        public static function selectOne($query, $bindings = []): object|null
        {
            $query = strtolower((string) $query);

            if (str_contains($query, 'information_schema.triggers')) {
                $triggerName = (string) ($bindings[0] ?? '');

                foreach (\Illuminate\Database\Schema\Schema::rawStatements()->statements as $sql) {
                    if (preg_match('/CREATE\s+TRIGGER\s+`?' . preg_quote($triggerName, '/') . '`?/i', $sql)) {
                        return new \stdClass();
                    }
                }

                return null;
            }

            if (str_contains($query, 'information_schema.statistics')) {
                [$tableName, $indexName] = array_pad($bindings, 2, '');
                $table = \Illuminate\Database\Schema\Schema::recorder()->tables[(string) $tableName] ?? null;

                foreach ($table?->indexes ?? [] as $index) {
                    if (($index['name'] ?? null) === $indexName) {
                        return new \stdClass();
                    }
                }

                foreach ($table?->columns ?? [] as $column) {
                    foreach ($column->indexes as $index) {
                        if (($index['name'] ?? null) === $indexName) {
                            return new \stdClass();
                        }
                    }
                }

                return null;
            }

            return new \stdClass();
        }

        /**
         * Stand-in for DB::table(). The sandbox records SCHEMA, it has no rows, so
         * every data probe on a fresh database answers "zero rows". The migration
         * that enforces tenant ownership uses exactly this: `DB::table($t)
         * ->whereNull('organization_id')->count() === 0` is the precondition for
         * the NOT NULL change, and a freshly created database does satisfy it.
         *
         * Without this method the migration aborted the whole run with "Call to
         * undefined method DB::table()", and the verifier stopped before reaching
         * any later migration (that is how the tenant campaign broke this gate).
         */
        public static function table(string $table): DbTableProbe
        {
            return new DbTableProbe($table);
        }

        /**
         * Stand-in for DB::select(). The stub records SCHEMA, it has no rows, so
         * every data probe answers "nothing found". A migration that asserts an
         * invariant on existing data ("no second published schema version per
         * hall") therefore passes — which is the state of a freshly created
         * database, the only state this verifier can reason about at all.
         *
         * Without this method such a migration aborted the whole run with
         * "Call to undefined method DB::select()", and the verifier stopped
         * before reaching any later migration.
         */
        public static function select($query, $bindings = []): array
        {
            return [];
        }
    }

    /**
     * The row-count probe DB::table() returns. Fluent chain is preserved
     * (`whereNull`, `where`), every terminal count answers zero: the verifier
     * reasons about an EMPTY database, the only state it can honestly know.
     */
    class DbTableProbe
    {
        public function __construct(private readonly string $table)
        {
        }

        public function whereNull(string ...$columns): self
        {
            return $this;
        }

        public function where(mixed ...$args): self
        {
            return $this;
        }

        public function count(string $expression = '*'): int
        {
            return 0;
        }

        /** Имя таблицы — для отладки сообщения об ошибке, если их когда-нибудь станет много. */
        public function __toString(): string
        {
            return $this->table;
        }
    }
}
