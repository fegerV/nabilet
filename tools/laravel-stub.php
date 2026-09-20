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
            $this->indexes[] = ['type' => 'unique', 'name' => $name];

            return $this;
        }

        public function index(?string $name = null): self
        {
            $this->indexes[] = ['type' => 'index', 'name' => $name];

            return $this;
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
            $fk = new ForeignKeyDefinition($this->name, $table, $column ?? 'id');

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
            // the diff silently vacuous.
            $fk = new ForeignKeyDefinition($column, '', 'id', $name);
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

        /** @param list<string>|string $columns */
        public function unique(array|string $columns, ?string $name = null): void
        {
            $this->indexes[] = [
                'columns' => is_array($columns) ? array_values($columns) : [$columns],
                'type' => 'unique',
                'name' => $name,
            ];
        }

        /** @param list<string>|string $columns */
        public function index(array|string $columns, ?string $name = null): void
        {
            $this->indexes[] = [
                'columns' => is_array($columns) ? array_values($columns) : [$columns],
                'type' => 'index',
                'name' => $name,
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
    }
}
