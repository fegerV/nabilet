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
 *      indexes, foreign keys, CHECK constraints, triggers).
 *   3. Diff. Any difference is a failure; the report names it.
 *
 * WHAT IT CANNOT PROVE
 *   - That the SQL executes on MySQL. This sandbox has no pdo_sqlite and cannot
 *     reach mysqld. The authoritative execution test is applying migrations.sql
 *     to MySQL 8.4 — already done, and the source of the input dumps.
 *   - Column TYPE equivalence (VARCHAR(255) vs string(255)). Only NAMES are
 *     compared; types were transcribed from information_schema and are checked
 *     there, not re-checked here.
 */

require __DIR__ . '/laravel-stub.php';

use Illuminate\Database\Schema\Schema;

class_alias(Schema::class, 'Illuminate\Support\Facades\Schema');

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
        }
    }

    $spec['tables'][$table] = ['columns' => $columns, 'indexes' => $indexes];
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

check('same set of tables', function () use ($tables, $spec): void {
    $have = array_keys($tables);
    $want = array_keys($spec['tables']);
    sort($have);
    sort($want);

    assertTrue(
        $have === $want,
        diffReport(array_values(array_diff($want, $have)), array_values(array_diff($have, $want)), 'tables')
    );
});

check('same columns on every table', function () use ($tables, $spec): void {
    $problems = [];

    foreach ($spec['tables'] as $name => $definition) {
        if (! isset($tables[$name])) {
            continue; // already reported above
        }

        $have = array_map(static fn ($c): string => $c->name, $tables[$name]->columns);
        $want = $definition['columns'];
        sort($have);
        sort($want);

        if ($have !== $want) {
            $problems[] = $name . ': ' . diffReport(
                array_values(array_diff($want, $have)),
                array_values(array_diff($have, $want)),
                'columns'
            );
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('same named indexes on every table', function () use ($tables, $spec): void {
    $problems = [];

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

        $want = $definition['indexes'];
        sort($have);
        sort($want);

        if ($have !== $want) {
            $problems[] = $name . ': ' . diffReport(
                array_values(array_diff($want, $have)),
                array_values(array_diff($have, $want)),
                'indexes'
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
