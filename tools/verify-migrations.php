<?php

declare(strict_types=1);

/**
 * Migration verification — proves database/migrations/ ≡ nabilet_core_spec/migrations.sql.
 *
 * Usage:
 *     php tools/verify-migrations.php
 *
 * WHY THIS EXISTS
 *   The Laravel migrations are GENERATED (tools/gen-migrations.py) from a MySQL
 *   instance loaded with the spec bundle. Generation is mechanical, so the failure
 *   mode is not "wrong by design" but "silently drifted" — a table skipped, a
 *   foreign key mapped to the wrong ON DELETE, a CHECK lost. Nothing in the
 *   migration set would notice.
 *
 *   So this tool does not merely run the migrations. It runs them, records what
 *   they declare, parses the spec SQL independently, and diffs the two.
 *
 * HOW
 *   1. Execute every migration through the Laravel stubs. Real code, real call
 *      chains — a missing Blueprint method or a Schema::table() on a table that
 *      does not exist yet is a hard failure here.
 *   2. Parse nabilet_core_spec/migrations.sql into the same shape (tables, columns,
 *      column defaults, indexes, foreign keys, CHECK constraints, triggers).
 *   3. Diff the Core schema strictly, then compare application-owned extensions against
 *      an exact, reasoned allowlist. Any unlisted/missing difference is a failure.
 *      This distinction is necessary because the repository intentionally carries
 *      application migrations beyond the standalone Core bundle; the allowlist is a
 *      ratchet and never suppresses an unreviewed column/table/index.
 *
 * WHY COLUMN DEFAULTS ARE COMPARED
 *   They were not, and that blind spot let a real defect through: gen-migrations.py
 *   read information_schema.COLUMN_DEFAULT (which reports `active`, unquoted) but only
 *   recognised the quoted `'active'` form, so it dropped every string default in the
 *   schema — 44 columns across 36 tables. Column NAMES still matched, so this tool was
 *   green while `users.status` (NOT NULL, default in the spec) had none, and every
 *   registration failed with MySQL 1364. Names are not enough: a default is part of the
 *   column's contract, and the schema is not equivalent without it.
 *
 * WHAT IT CANNOT PROVE
 *   - That the SQL executes on MySQL. This sandbox has no pdo_sqlite and cannot
 *     reach mysqld. The authoritative execution test is applying migrations.sql
 *     to MySQL 8.4 — already done, and the source of the input dumps.
 *   - Column TYPE equivalence (VARCHAR(255) vs string(255)). Only NAMES and DEFAULTS
 *     are compared; types were transcribed from information_schema and are checked
 *     there, not re-checked here.
 */

require __DIR__ . '/laravel-stub.php';

use Illuminate\Database\Schema\Schema;

class_alias(Schema::class, 'Illuminate\Support\Facades\Schema');

/**
 * Tables created by application migrations that are intentionally absent from the
 * NABILET Core spec bundle. The spec is the "core" schema (64 tables); these are
 * product/integration tables layered on top and so have no spec definition to diff
 * against. They are allowed to exist in the migration set.
 *
 * This is a ratchet: it may only shrink. If one of these tables becomes part of the
 * core spec, remove it here and the "same set of tables" check will then require it.
 *
 * @var list<string>
 */
const APP_ONLY_TABLES = [
    'event_artists',          // event content extras (create_event_content_tables)
    'event_dates',            // event scheduling (create_event_dates_table)
    'event_faqs',             // event content extras
    'event_schedule_items',   // event schedule
    'event_speakers',         // event content extras
    'event_sponsors',         // event content extras
    'metrika_settings',       // Yandex.Metrika integration (create_metrika_settings_table)
    'personal_access_tokens', // Laravel personal access tokens (create_personal_access_tokens_table)
    'failed_jobs',            // Laravel queue bookkeeping (create_queue_tables); needed to see a webhook that exhausted its attempts
    'jobs',                   // Laravel database queue (create_queue_tables); transactional mail and outbound webhooks are dispatched onto it
    'order_reminders',        // day-before reminder log (create_order_reminders_table); unique(order_id) is the only idempotency guard a cron-driven sweep can rely on
];

/**
 * Columns that application migrations add on top of the NABILET Core spec.
 * Like APP_ONLY_TABLES, these are product-layer extensions with no spec
 * definition, so they are permitted to exist in the migration set. They are
 * excluded from the column and default diffs below.
 *
 * Ratchet: may only shrink. If a column below becomes part of the core spec,
 * remove it here and the column/defaults checks will then require it.
 *
 * @var list<string>  "table.column"
 */
const APP_OWNED_COLUMNS = [
    'users.remember_token',                  // Laravel auth (add_remember_token)
    'carts.currency',                       // Cart model writes it; backfilled (add_currency_total_to_carts)
    'carts.total_amount',                   // CartService checkout math; backfilled (add_currency_total_to_carts)
];

/**
 * Indexes that application migrations add on top of the NABILET Core spec.
 * Excluded from the named-index diff below.
 *
 * @var list<string>  "table.index"
 */
const APP_OWNED_INDEXES = [
    'offline_bundles.idx_offline_bundles_device_hash', // multi-device sync (fix_offline_bundles_unique_constraint)
    'offline_bundles.uq_offline_bundles_hash_device',  // replaced uq_offline_bundles_hash (fix_offline_bundles_unique_constraint)
];

/**
 * Spec-declared indexes that migrations DELIBERATELY do not apply.
 *
 * This is a real, deferred spec/migration divergence — NOT an app extension.
 * Migration 2026_10_05_000200_constrain_carts_unique_to_active.php dropped
 * `uq_carts_token_session_status` (it raised a 500 on the second purchase of a
 * session) and replaced it with `uq_carts_active` over a generated
 * `active_cart_key` column. The spec still declares the old key and must be
 * updated in a separate, explicit change. Until then the migrations are correct
 * and this name is excluded from the "missing from migrations" check.
 *
 * Ratchet: may only shrink. When the spec is updated to match, delete the entry.
 *
 * @var list<string>  "table.index"
 */
const KNOWN_SPEC_INDEX_DIVERGENCES = [
    'carts.uq_carts_token_session_status',
];

/**
 * Strip the entries of `table.<thing>` that belong to $table from a qualified list.
 *
 * @param list<string> $qualified  "table.column" / "table.index"
 * @return list<string>            the bare names for $table
 */
function ownEntries(array $qualified, string $table): array
{
    $out = [];
    $prefix = $table . '.';

    foreach ($qualified as $entry) {
        if (str_starts_with($entry, $prefix)) {
            $out[] = substr($entry, strlen($prefix));
        }
    }

    return $out;
}

$root = dirname(__DIR__);
$specFile = $root . '/nabilet_core_spec/migrations.sql';
$migrationDir = $root . '/database/migrations';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, callable $assertion): void
{
    global $passed, $failed, $failures;

    try {
        $assertion();
        $passed++;
        printf("  \u{2713} %s\n", $label);
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $label . ' — ' . $e->getMessage();
        printf("  \u{2717} %s\n      %s\n", $label, $e->getMessage());
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $a */
function diffReport(array $missing, array $extra, string $what): string
{
    $parts = [];

    if ($missing !== []) {
        $parts[] = 'missing from migrations: ' . implode(', ', $missing);
    }

    if ($extra !== []) {
        $parts[] = 'not in spec: ' . implode(', ', $extra);
    }

    return $what . ' — ' . implode('; ', $parts);
}

/**
 * One canonical spelling for a DEFAULT, from either side.
 *
 * The two sources say the same thing in different dialects:
 *
 *   spec SQL                    recorded from the migration
 *   --------------------------  -------------------------------------
 *   DEFAULT 'active'            'active'        (stub quotes strings)
 *   DEFAULT 0.00                0               (PHP casts the literal to float)
 *   DEFAULT CURRENT_TIMESTAMP   CURRENT_TIMESTAMP
 *   DEFAULT NULL                hasDefault = false
 *
 * Comparing the raw text would report every one of those as drift, so both sides are
 * folded into the value itself: `s:` for a string, `n:` for a number (compared as a
 * float, so 0.00 ≡ 0), `ts:` for a current-timestamp, and null for "no default".
 * A NULL default and an absent default are the same thing here, deliberately — the
 * schema cannot tell them apart and neither should the diff.
 */
function normalizeDefault(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    if (preg_match("/^'(.*)'$/s", $raw, $m)) {
        return 's:' . str_replace("''", "'", $m[1]);
    }

    if (strcasecmp($raw, 'NULL') === 0) {
        return null;
    }

    if (preg_match('/^(?:CURRENT_TIMESTAMP(?:\(\d*\))?|NOW\(\))$/i', $raw)) {
        return 'ts:CURRENT_TIMESTAMP';
    }

    if (is_numeric($raw)) {
        return 'n:' . (string) (float) $raw;
    }

    return 'x:' . $raw;
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNABILET Core — migration verification\n";
echo str_repeat('─', 74), "\n";
echo "\n  spec:        nabilet_core_spec/migrations.sql\n";
echo "  migrations:  database/migrations/\n";

// ── 1. Execute the migrations ────────────────────────────────────────────────
$files = glob($migrationDir . '/*.php') ?: [];
sort($files);

assertTrue($files !== [], 'No migration files found in ' . $migrationDir);

echo "\n[1] Executing migrations\n";

foreach ($files as $file) {
    $migration = require $file;

    if (! $migration instanceof Illuminate\Database\Migrations\Migration) {
        throw new RuntimeException('Not a Migration instance: ' . basename($file));
    }

    $migration->up();
    printf("  \u{2713} %s\n", basename($file, '.php'));
}

$recorder = Schema::recorder();
$tables = $recorder->tables;
$rawStatements = Schema::rawStatements()->statements;

// Indexes created with raw DDL are invisible to Blueprint, yet the spec declares
// them — and several migrations legitimately reach for raw DDL where Blueprint
// cannot express the syntax (an index prefix length, e.g. `source(512)`, or a
// driver-specific form). Without this, the index diff below reports drift that
// is not there: it flagged `heatmap_events.idx_heatmap_page_time` (a raw
// CREATE INDEX) and, once `redirects.uq_redirect_source` moved to a raw
// ALTER TABLE to keep its prefix length, that one too. Fold the raw index DDL
// back into the recorded tables so the diff sees the schema that is actually
// declared.
foreach ($rawStatements as $rawSql) {
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?\s+(\w+)(?:\((\d+)\))?/i', $rawSql, $rm)) {
        if (isset($tables[$rm[1]])) {
            $columnExists = false;
            foreach ($tables[$rm[1]]->columns as $column) {
                if ($column->name === $rm[2]) {
                    $columnExists = true;
                    break;
                }
            }

            if (!$columnExists) {
                $type = strtolower($rm[3]) === 'varchar' ? 'string' : strtolower($rm[3]);
                $length = isset($rm[4]) && $rm[4] !== '' ? (int) $rm[4] : null;
                $tables[$rm[1]]->columns[] = new Illuminate\Database\Schema\ColumnDefinition($type, $rm[2], $length);
            }
        }

        continue;
    }

    if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+ON\s+`?(\w+)`?/i', $rawSql, $rm)) {
        if (isset($tables[$rm[2]])) {
            $tables[$rm[2]]->indexes[] = ['columns' => [], 'type' => 'index', 'name' => $rm[1]];
        }

        continue;
    }

    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i', $rawSql, $rm)) {
        if (isset($tables[$rm[1]])) {
            $tables[$rm[1]]->indexes[] = ['columns' => [], 'type' => 'index', 'name' => $rm[2]];
        }

        continue;
    }

    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+DROP\s+INDEX\s+`?(\w+)`?/i', $rawSql, $rm)) {
        if (isset($tables[$rm[1]])) {
            $tables[$rm[1]]->indexes = array_values(array_filter(
                $tables[$rm[1]]->indexes,
                static fn (array $index): bool => ($index['name'] ?? null) !== $rm[2],
            ));
        }

        continue;
    }

    // NOTE — why raw `ALTER … SET DEFAULT` is deliberately NOT folded in here.
    //
    // 2026_10_05_000100_restore_spec_column_defaults.php exists to repair installs
    // built before the generator fix, and it sets all 85 spec defaults through raw
    // DDL. Folding it in — the way raw index DDL is folded above — would make the
    // defaults diff below vacuous: delete `->default('active')` from the identity
    // migration and the check would still pass, because the repair would cover it.
    // Verified: it does exactly that. The two cases are not alike — an index created
    // by raw DDL has no other declaration anywhere, whereas a column default is
    // declared by the CREATE TABLE migration that owns the column. So the diff
    // compares the spec against the *generated* migrations, which is where the bug
    // was and where a regeneration could reintroduce it. The repair migration is a
    // one-off static transcription; what it produces is confirmed by applying it.
}

printf("\n  %d migrations, %d tables declared, %d raw SQL statements\n",
    count($files), count($tables), count($rawStatements));

// ── 2. Parse the spec ────────────────────────────────────────────────────────
echo "\n[2] Parsing the spec bundle\n";

$specSql = file_get_contents($specFile);
assertTrue($specSql !== false, 'Cannot read ' . $specFile);

$spec = [
    'tables' => [],   // name => ['columns' => [...], 'indexes' => [...]]
    'fks' => [],      // name => "table.column -> ref(col) ON DELETE X"
    'checks' => [],   // name => true
    'triggers' => [], // name => true
];

// Tables: from "CREATE TABLE [IF NOT EXISTS] x (" to the closing ") ENGINE..."
preg_match_all(
    '/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\)\s*ENGINE=/si',
    $specSql,
    $matches,
    PREG_SET_ORDER
);

foreach ($matches as $m) {
    $table = $m[1];
    $body = $m[2];
    $columns = [];
    $indexes = [];
    $defaults = [];

    foreach (explode("\n", $body) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '--')) {
            continue;
        }

        if (preg_match('/^(?:UNIQUE\s+KEY|KEY|INDEX)\s+`?(\w+)`?\s*\(/i', $line, $im)) {
            $indexes[] = $im[1];

            continue;
        }

        // CONSTRAINT <name> FOREIGN KEY (col) REFERENCES tbl(col) ON DELETE X
        if (preg_match(
            '/^CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY\s*\(`?(\w+)`?\)\s*REFERENCES\s+`?(\w+)`?\s*\(\s*`?(\w+)`?\s*\)(?:\s*ON DELETE\s+(CASCADE|RESTRICT|SET NULL|NO ACTION))?/i',
            $line,
            $fm
        )) {
            $spec['fks'][$fm[1]] = sprintf(
                '%s.%s -> %s(%s) ON DELETE %s',
                $table,
                $fm[2],
                $fm[3],
                $fm[4],
                strtoupper($fm[5] ?? 'NO ACTION')
            );

            continue;
        }

        // \s, not \b: without it this also matched the columns `checksum` and
        // `checkin_device_id`, silently dropping them from the spec side and
        // reporting the (correct) migration as drifted.
        if (preg_match('/^(PRIMARY\s+KEY|CONSTRAINT|CHECK\s)/i', $line)) {
            continue;
        }

        // A plain column definition: first token is the column name.
        if (preg_match('/^`?(\w+)`?\s+/', $line, $cm)) {
            $columns[] = $cm[1];

            // DEFAULT <literal>: a quoted string, a number, or a bare keyword such
            // as CURRENT_TIMESTAMP. Captured as written — `normalizeDefault()`
            // folds it against what the migration recorded. Bounded so that an
            // `ON UPDATE` clause or a trailing comment is not swept in.
            if (preg_match(
                "/\bDEFAULT\s+('(?:[^']*)'|[\w()]+|-?\d+(?:\.\d+)?)/i",
                $line,
                $dm
            )) {
                $defaults[$cm[1]] = $dm[1];
            }
        }
    }

    $spec['tables'][$table] = [
        'columns' => $columns,
        'indexes' => $indexes,
        'defaults' => $defaults,
    ];
}

// ALTER TABLE carries three things the CREATE blocks do not: columns added by the
// TZ-gap closure (010), keys added alongside them, and every CHECK constraint.
// A CHECK body may span lines and contain commas, so clauses are matched
// individually rather than by splitting on commas.
preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);\s*(?:\n|$)/si', $specSql, $alters, PREG_SET_ORDER);

foreach ($alters as $alter) {
    $table = $alter[1];
    $body = $alter[2];

    foreach (['ADD COLUMN', 'ADD KEY', 'ADD INDEX', 'ADD UNIQUE KEY'] as $prefix) {
        if (preg_match_all('/' . $prefix . '\s+`?(\w+)`?/i', $body, $am)) {
            foreach ($am[1] as $name) {
                if ($prefix === 'ADD COLUMN') {
                    $spec['tables'][$table]['columns'][] = $name;
                } else {
                    $spec['tables'][$table]['indexes'][] = $name;
                }
            }
        }
    }

    // A DEFAULT on an ADD COLUMN is part of that column's contract too. None of the
    // current bundle has one, but the CREATE TABLE path checks for it and a column
    // added by ALTER would otherwise be the single place the diff looked away.
    if (preg_match_all(
        "/ADD\s+COLUMN\s+`?(\w+)`?\s+[^,;]*?\bDEFAULT\s+('(?:[^']*)'|[\w()]+|-?\d+(?:\.\d+)?)/i",
        $body,
        $dm,
        PREG_SET_ORDER
    )) {
        foreach ($dm as $d) {
            $spec['tables'][$table]['defaults'][$d[1]] = $d[2];
        }
    }

    // ADD CONSTRAINT <name> FOREIGN KEY (col) REFERENCES tbl(col) ON DELETE X
    if (preg_match_all(
        '/ADD\s+CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY\s*\(`?(\w+)`?\)\s*REFERENCES\s+`?(\w+)`?\s*\(\s*`?(\w+)`?\s*\)(?:\s*ON DELETE\s+(CASCADE|RESTRICT|SET NULL|NO ACTION))?/i',
        $body,
        $fm,
        PREG_SET_ORDER
    )) {
        foreach ($fm as $f) {
            $spec['fks'][$f[1]] = sprintf(
                '%s.%s -> %s(%s) ON DELETE %s',
                $table,
                $f[2],
                $f[3],
                $f[4],
                strtoupper($f[5] ?? 'NO ACTION')
            );
        }
    }

    // DROP CONSTRAINT <name> — 010 widens ck_tickets_status, so the last
    // definition of a name is the one that survives.
    if (preg_match_all('/DROP\s+CONSTRAINT\s+`?(\w+)`?/i', $body, $dm)) {
        foreach ($dm[1] as $name) {
            unset($spec['fks'][$name], $spec['checks'][$name]);
        }
    }
}

// CHECK constraints: matched over the whole file because bodies span lines.
preg_match_all('/(?:ADD\s+)?CONSTRAINT\s+`?(\w+)`?\s+CHECK\s*\(/i', $specSql, $cm);
foreach ($cm[1] as $name) {
    $spec['checks'][$name] = true;
}

preg_match_all('/CREATE TRIGGER\s+`?(\w+)`?/i', $specSql, $tm);
foreach ($tm[1] as $name) {
    $spec['triggers'][$name] = true;
}

printf("  spec: %d tables, %d foreign keys, %d CHECK constraints, %d trigger(s)\n",
    count($spec['tables']), count($spec['fks']), count($spec['checks']), count($spec['triggers']));

// ── 3. Structural checks on what the migrations declared ─────────────────────
echo "\n[3] Structure\n";

check('every foreign key points at a table that exists', function () use ($tables, $recorder): void {
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        foreach ($blueprint->foreignKeys as $fk) {
            if ($fk->table !== '' && ! isset($tables[$fk->table])) {
                $problems[] = sprintf('%s.%s -> %s (missing)', $name, $fk->column, $fk->table);
            }
        }
    }

    foreach ($recorder->addedForeignKeys as $table => $fks) {
        foreach ($fks as $fk) {
            if (! isset($tables[$fk->table])) {
                $problems[] = sprintf('%s.%s -> %s (missing, deferred)', $table, $fk->column, $fk->table);
            }
        }
    }

    assertTrue($problems === [], "Unresolved foreign keys:\n      " . implode("\n      ", $problems));
});

check('every foreign key column is itself declared on the table', function () use ($tables, $recorder): void {
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        $columns = array_map(static fn ($c): string => $c->name, $blueprint->columns);
        $fks = $blueprint->foreignKeys;

        foreach ($recorder->addedForeignKeys[$name] ?? [] as $fk) {
            $fks[] = $fk;
        }

        foreach ($fks as $fk) {
            if (! in_array($fk->column, $columns, true)) {
                $problems[] = sprintf('%s.%s has a FK but no column declaration', $name, $fk->column);
            }
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('every index column exists on its table', function () use ($tables): void {
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        $columns = array_map(static fn ($c): string => $c->name, $blueprint->columns);
        $indexes = $blueprint->indexes;

        foreach ($blueprint->columns as $col) {
            foreach ($col->indexes as $idx) {
                $indexes[] = ['columns' => [$col->name]];
            }
        }

        foreach ($indexes as $index) {
            foreach ($index['columns'] as $column) {
                if (! in_array($column, $columns, true)) {
                    $problems[] = sprintf('%s: index on unknown column \"%s\"', $name, $column);
                }
            }
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('no index name is used twice', function () use ($tables): void {
    $seen = [];
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        $names = [];

        foreach ($blueprint->indexes as $index) {
            $names[] = $index['name'] ?? ('auto:' . $name . ':' . implode('_', $index['columns']));
        }

        foreach ($blueprint->columns as $col) {
            foreach ($col->indexes as $idx) {
                $names[] = $idx['name'] ?? ('auto:' . $name . ':' . $col->name);
            }
        }

        foreach ($names as $indexName) {
            if (str_starts_with((string) $indexName, 'auto:')) {
                continue;
            }

            if (isset($seen[$indexName])) {
                $problems[] = sprintf('"%s" used by both %s and %s', $indexName, $seen[$indexName], $name);
            }

            $seen[$indexName] = $name;
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

// ── 4. The diff that matters: migrations vs spec ─────────────────────────────
echo "\n[4] Migrations vs spec\n";

/**
 * Exact, reviewed application extensions that intentionally sit outside the Core spec.
 * This is a ratchet, not a broad ignore list: every expected difference is named,
 * and any additional/missing difference still fails the gate.
 *
 * Table owners: event_dates (2026_09_22_001400), event content tables
 * (2026_09_22_001500), metrika_settings (2026_09_30_000200), and Sanctum's
 * personal_access_tokens (2026_09_24_083529; separately documented as a design mismatch).
 * Column owners: remember_token (2026_09_22_001300), carts currency/total_amount
 * (2026_09_24_000001), carts.active_cart_key and its active-only index
 * (2026_10_05_000200), and hall schema revision + generated live-version columns
 * (2026_10_06_000100, 2026_10_06_000200).
 * Index owners: 2026_09_20_001200 replaces the globally unique bundle hash with
 * per-device uniqueness and adds a device/hash lookup index; 2026_10_05_000200
 * replaces the carts token/session/status key with a generated active-only key;
 * 2026_10_06_000200 enforces one live published/draft schema version per hall via
 * generated-column UNIQUE indexes.
 */
$knownExtensions = [
    'tables' => [
        'event_artists',
        'event_dates',
        'event_faqs',
        'event_schedule_items',
        'event_speakers',
        'event_sponsors',
        'metrika_settings',
        'personal_access_tokens',
        'storefront_settings', // конструктор витрины (Storefront)
        'failed_jobs',         // очередь Laravel (create_queue_tables)
        'jobs',                // очередь Laravel: транзакционная почта и исходящие вебхуки
        'order_reminders',     // журнал напоминаний за сутки (create_order_reminders_table)
    ],
    'columns' => [
        'users' => ['remember_token'],
        'carts' => ['active_cart_key', 'currency', 'total_amount'],
        'hall_schema_versions' => ['revision', 'published_hall_id', 'draft_hall_id'],
        // Сведения о зале и цена места (add_hall_details_and_seat_price):
        // детали зала правятся отдельно от версии схемы, цена на место
        // перекрывает цену ряда.
        'halls' => ['city', 'address', 'exterior_photo_url', 'interior_photo_url'],
        'seats' => ['price_amount'],
    ],
    // Значения по умолчанию для app-only колонок сюда НЕ пишутся: такие колонки
    // перечислены в APP_OWNED_COLUMNS и вычитаются из сравнения до diff-а.
    // Дублирование (было для carts.currency/carts.total_amount) ломало проверку
    // в обе стороны: колонка вычиталась, а ожидалась — и diff никогда не сходился.
    'defaults' => [],
    'indexes' => [
        'carts' => [
            'missing' => ['uq_carts_token_session_status'],
            'extra' => ['uq_carts_active'],
        ],
        'offline_bundles' => [
            'missing' => ['uq_offline_bundles_hash'],
            'extra' => ['idx_offline_bundles_device_hash', 'uq_offline_bundles_hash_device'],
        ],
        'hall_schema_versions' => [
            'extra' => ['uq_schema_one_published_per_hall', 'uq_schema_one_draft_per_hall'],
        ],
    ],
];

function assertExactDiff(array $actualMissing, array $actualExtra, array $expectedMissing, array $expectedExtra, string $what): void
{
    sort($actualMissing);
    sort($actualExtra);
    sort($expectedMissing);
    sort($expectedExtra);

    assertTrue(
        $actualMissing === $expectedMissing && $actualExtra === $expectedExtra,
        sprintf(
            '%s differs: observed missing [%s], extra [%s]; expected missing [%s], extra [%s]',
            $what,
            implode(', ', $actualMissing),
            implode(', ', $actualExtra),
            implode(', ', $expectedMissing),
            implode(', ', $expectedExtra),
        )
    );
}

check('Core tables match; only named application tables are additional', function () use ($tables, $spec, $knownExtensions): void {
    $have = array_keys($tables);
    $want = array_keys($spec['tables']);

    assertExactDiff(
        array_values(array_diff($want, $have)),
        array_values(array_diff($have, $want)),
        [],
        $knownExtensions['tables'],
        'table set',
    );
});

check('Core columns match; only named application columns are additional', function () use ($tables, $spec, $knownExtensions): void {
    $problems = [];

    foreach ($spec['tables'] as $name => $definition) {
        if (! isset($tables[$name])) {
            continue; // missing table is reported by the table-set check
        }

        $have = array_map(static fn ($c): string => $c->name, $tables[$name]->columns);
        $want = $definition['columns'];
        $missing = array_values(array_diff($want, $have));
        $extra = array_values(array_diff($have, $want));
        $expectedExtra = $knownExtensions['columns'][$name] ?? [];

        sort($missing);
        sort($extra);
        sort($expectedExtra);

        if ($missing !== [] || $extra !== $expectedExtra) {
            $problems[] = sprintf(
                '%s: missing [%s], extra [%s]; expected missing [], extra [%s]',
                $name,
                implode(', ', $missing),
                implode(', ', $extra),
                implode(', ', $expectedExtra),

            );
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

/**
 * Column defaults are compared, not just column names.
 *
 * This is the check that was missing. Column names matched perfectly while 44 columns
 * had silently lost their DEFAULT, because the generator dropped string defaults on
 * the way from information_schema. A default is part of what a column *is*: `status
 * VARCHAR(32) NOT NULL DEFAULT 'active'` and `status VARCHAR(32) NOT NULL` accept
 * different INSERTs, and the second one rejects every registration.
 *
 * Both sides are folded through `normalizeDefault()` first, so `'active'`/`active`,
 * `0.00`/`0` and a NULL default versus no default are not reported as drift.
 */
check('Core column defaults match; app-only defaults are explicitly named', function () use ($tables, $spec, $knownExtensions): void {
    $problems = [];

    foreach ($spec['tables'] as $name => $definition) {
        if (! isset($tables[$name])) {
            continue; // already reported above
        }

        $want = [];
        foreach ($definition['defaults'] ?? [] as $column => $literal) {
            $normalized = normalizeDefault($literal);
            if ($normalized !== null) {
                $want[$column] = $normalized;
            }
        }

        $have = [];
        foreach ($tables[$name]->columns as $column) {
            if ($column->hasDefault && ($normalized = normalizeDefault($column->default)) !== null) {
                $have[$column->name] = $normalized;
            }
        }

        // App-owned columns have no spec default either (see APP_OWNED_COLUMNS).
        // Strip them before diffing so they are not reported as drift.
        foreach (ownEntries(APP_OWNED_COLUMNS, $name) as $col) {
            unset($have[$col], $want[$col]);
        }

        $missing = [];
        $extra = [];

        foreach ($want as $column => $value) {
            if (! array_key_exists($column, $have)) {
                $missing[] = $column . ' (spec: ' . $value . ')';
            } elseif ($have[$column] !== $value) {
                $missing[] = $column . ' (spec: ' . $value . ', migration: ' . $have[$column] . ')';
            }
        }

        foreach ($have as $column => $value) {
            if (! array_key_exists($column, $want)) {
                $extra[] = $column . ' (migration: ' . $value . ')';
            }
        }

        $expectedExtra = [];
        foreach ($knownExtensions['defaults'][$name] ?? [] as $column => $value) {
            $expectedExtra[] = $column . ' (migration: ' . $value . ')';
        }

        sort($missing);
        sort($extra);
        sort($expectedExtra);

        if ($missing !== [] || $extra !== $expectedExtra) {
            $problems[] = $name . ': ' . diffReport(
                $missing,
                array_values(array_merge(
                    array_diff($expectedExtra, $extra),
                    array_diff($extra, $expectedExtra),
                )),
                'defaults (only explicitly listed app defaults are permitted)'
            );
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('Core indexes match; application-owned offline-bundle indexes are explicit', function () use ($tables, $spec, $knownExtensions): void {
    $problems = [];

    // Индексы, созданные сырым SQL (`CREATE UNIQUE INDEX ... ON <table>`),
    // рекордер схемы не видит: он собирает только объявления Blueprint. Без
    // этого разбора инвариант «одна живая версия схемы на зал» выглядел бы
    // несуществующим, хотя миграция его создаёт.
    $rawIndexes = [];
    foreach (\Illuminate\Database\Schema\Schema::rawStatements()->statements as $sql) {
        if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?/i', (string) $sql, $m)) {
            $rawIndexes[strtolower($m[2])][] = $m[1];
        }
    }

    foreach ($spec['tables'] as $name => $definition) {
        if (! isset($tables[$name])) {
            continue;
        }

        $have = [];

        foreach ($tables[$name]->indexes as $index) {
            if ($index['name'] !== null) {
                $have[] = $index['name'];
            }
        }

        foreach ($rawIndexes[strtolower($name)] ?? [] as $indexName) {
            if (! in_array($indexName, $have, true)) {
                $have[] = $indexName;
            }
        }

        $want = $definition['indexes'];
        $expected = $knownExtensions['indexes'][$name] ?? [];
        $missing = array_values(array_diff($want, $have));
        $extra = array_values(array_diff($have, $want));
        // Оба ключа необязательны: запись может называть только `extra` (так для
        // hall_schema_versions). Без `?? []` в sort() уходил null и вся проверка
        // падала, не дойдя до сравнения.
        $expectedMissing = $expected['missing'] ?? [];
        $expectedExtra = $expected['extra'] ?? [];
        sort($missing);
        sort($extra);
        sort($expectedMissing);
        sort($expectedExtra);

        if ($missing !== $expectedMissing || $extra !== $expectedExtra) {
            $problems[] = sprintf(
                '%s: missing [%s], extra [%s]; expected missing [%s], extra [%s]',
                $name,
                implode(', ', $missing),
                implode(', ', $extra),
                implode(', ', $expectedMissing),
                implode(', ', $expectedExtra),

            );
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('same foreign keys, including ON DELETE', function () use ($tables, $recorder, $spec): void {
    $have = [];

    foreach ($tables as $name => $blueprint) {
        foreach ($blueprint->foreignKeys as $fk) {
            if ($fk->name === null || $fk->table === '') {
                continue;
            }

            $have[(string) $fk->name] = sprintf(
                '%s.%s -> %s(%s) ON DELETE %s',
                $name,
                $fk->column,
                $fk->table,
                $fk->references ?? 'id',
                $fk->onDelete
            );
        }
    }

    foreach ($recorder->addedForeignKeys as $name => $fks) {
        foreach ($fks as $fk) {
            $have[(string) $fk->name] = sprintf(
                '%s.%s -> %s(%s) ON DELETE %s',
                $name,
                $fk->column,
                $fk->table,
                $fk->references ?? 'id',
                $fk->onDelete
            );
        }
    }

    $want = $spec['fks'];
    ksort($have);
    ksort($want);

    $problems = [];

    foreach ($want as $name => $definition) {
        if (! isset($have[$name])) {
            $problems[] = $name . ' is in the spec but not declared';
        } elseif ($have[$name] !== $definition) {
            $problems[] = sprintf('%s: spec says "%s", migration says "%s"', $name, $definition, $have[$name]);
        }
    }

    foreach (array_keys($have) as $name) {
        if (! isset($want[$name])) {
            $problems[] = $name . ' is declared but not in the spec';
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('every CHECK constraint from the spec is applied', function () use ($rawStatements, $spec): void {
    $have = [];

    foreach ($rawStatements as $sql) {
        if (preg_match('/ADD\s+CONSTRAINT\s+`?(\w+)`?\s+CHECK/i', $sql, $m)) {
            $have[$m[1]] = true;
        }
    }

    $want = $spec['checks'];
    ksort($have);
    ksort($want);

    assertTrue(
        array_keys($have) === array_keys($want),
        diffReport(
            array_values(array_diff(array_keys($want), array_keys($have))),
            array_values(array_diff(array_keys($have), array_keys($want))),
            'CHECK constraints'
        )
    );
});

check('the hall-schema immutability trigger is created', function () use ($rawStatements, $spec): void {
    $have = [];

    foreach ($rawStatements as $sql) {
        if (preg_match('/CREATE\s+TRIGGER\s+`?(\w+)`?/i', $sql, $m)) {
            $have[$m[1]] = true;
        }
    }

    $missing = array_values(array_diff(array_keys($spec['triggers']), array_keys($have)));
    assertTrue($missing === [], 'Triggers in the spec but never created: ' . implode(', ', $missing));
});

// ── 5. Targeted invariants worth naming explicitly ───────────────────────────
echo "\n[5] Domain invariants\n";

check('money columns are integers, never floats', function () use ($tables): void {
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        foreach ($blueprint->columns as $col) {
            if (preg_match('/_(amount|price|fee|total)$/', $col->name) && $col->type === 'decimal') {
                $problems[] = $name . '.' . $col->name . ' is decimal (money must be integer minor units)';
            }
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('revoked tickets are excluded from the terminal-state guard', function () use ($rawStatements): void {
    $found = false;

    foreach ($rawStatements as $sql) {
        if (str_contains($sql, 'ck_tickets_terminal_exclusive')) {
            $found = true;

            // used_at may not coexist with cancelled_at/refunded_at, but a ticket
            // that was admitted and later revoked by chargeback is legitimate and
            // must stay reportable — so revoked_at is deliberately absent here.
            assertTrue(
                ! str_contains($sql, 'revoked_at'),
                'ck_tickets_terminal_exclusive must not mention revoked_at (a chargeback '
                . 'after admission is legal and must remain reportable)'
            );
        }
    }

    assertTrue($found, 'ck_tickets_terminal_exclusive was never applied');
});

check('tickets.status accepts revoked', function () use ($rawStatements): void {
    foreach ($rawStatements as $sql) {
        if (str_contains($sql, 'ck_tickets_status')) {
            assertTrue(
                str_contains($sql, "'revoked'"),
                'tickets.status must accept revoked (TZ 43/44)'
            );

            return;
        }
    }

    throw new RuntimeException('ck_tickets_status was never applied');
});

check('hall schema revision is nullable and has no default', function () use ($tables): void {
    $columns = $tables['hall_schema_versions']->columns ?? [];

    foreach ($columns as $column) {
        if ($column->name === 'revision') {
            assertTrue($column->isNullable, 'hall_schema_versions.revision must be nullable for legacy rows');
            assertTrue(!$column->hasDefault, 'hall_schema_versions.revision must not have a default');

            return;
        }
    }

    throw new RuntimeException('hall_schema_versions.revision was never declared');
});

check('hall lifecycle triggers are emitted without a replacement gap', function () use ($rawStatements): void {
    $created = [];
    $lifecyclePosition = null;
    $legacyDropPosition = null;

    foreach ($rawStatements as $position => $sql) {
        if (preg_match('/CREATE\\s+TRIGGER\\s+`?(trg_\\w+)`?/i', $sql, $match)) {
            $created[$match[1]] = true;

            if ($match[1] === 'trg_schema_version_lifecycle_guard') {
                $lifecyclePosition = $position;
            }
        }

        if (preg_match('/DROP\\s+TRIGGER\\s+IF\\s+EXISTS\\s+`?trg_schema_version_immutable`?/i', $sql)) {
            $legacyDropPosition = $position;
        }
    }

    foreach ([
        'trg_schema_version_lifecycle_guard',
        'trg_schema_version_delete_guard',
        'trg_hall_frozen_schema_delete_guard',
    ] as $trigger) {
        assertTrue(isset($created[$trigger]), $trigger . ' was never created');
    }

    assertTrue(
        $lifecyclePosition !== null && $legacyDropPosition !== null && $lifecyclePosition < $legacyDropPosition,
        'The lifecycle update trigger must be created before the legacy guard is dropped'
    );
});

// ── summary ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 74), "\n";
printf("  %d passed, %d failed\n", $passed, $failed);

if ($failed > 0) {
    echo "\n  Failures:\n";

    foreach ($failures as $failure) {
        echo '    - ' . $failure . "\n";
    }

    echo "\n";
    exit(1);
}

echo "  migrations are equivalent to the spec bundle\n\n";
exit(0);
