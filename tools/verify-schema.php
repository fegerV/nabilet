<?php

declare(strict_types=1);

/**
 * Schema verification.
 *
 * Usage:
 *     php tools/verify-schema.php
 *
 * WHAT IT DOES
 *   1. Executes every migration file through the Laravel Schema stubs, so the real
 *      migration code runs.
 *   2. Builds SQLite DDL from the recorded definitions and executes it — proving the
 *      schema is internally consistent (every FK target exists, no duplicate index
 *      names, no malformed column types).
 *   3. Asserts the COMMERCIAL INVARIANTS with real INSERTs against a real database:
 *      the anti-double-sell constraints, the webhook idempotency guard, the
 *      one-ticket-per-order-item guard, and the RESTRICT delete policy that protects
 *      sales history.
 *
 * WHY THESE INVARIANTS AND NOT OTHERS
 *   Everything else in the schema can be fixed with a follow-up migration. These
 *   cannot: a duplicated hold or a double-issued ticket means two people hold a valid
 *   ticket for one seat, and that is not repairable after the fact. They are the
 *   constraints that must be enforced by the database rather than by application code,
 *   because application code is exactly what fails under concurrency.
 *
 * LIMITATIONS (stated honestly)
 *   SQLite is not MySQL. This verifies structure and constraint semantics; it does not
 *   verify MySQL-specific concerns (engine, collation, the 191-char index budget,
 *   isolation levels). Those are covered by the real test suite on a MySQL CI job.
 */

require __DIR__ . '/laravel-stub.php';

use Illuminate\Database\Schema\Schema;
use Illuminate\Support\Facades\Schema as SchemaFacade;

// The migrations import Illuminate\Support\Facades\Schema; alias it to our recorder.
class_alias(Schema::class, 'Illuminate\Support\Facades\Schema');

$root = dirname(__DIR__);
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

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNABILET Core — schema verification\n";
echo str_repeat('─', 74), "\n";
echo "\n  ⚠  SUPERSEDED TOOL. This checks database/migrations/, which describes a\n";
echo "     DIFFERENT schema (66 tables) and is NOT the source of truth. The\n";
echo "     authoritative schema is migrations.sql / nabilet_core_spec/migrations.\n";
echo "     For the real verification run, against MySQL:\n";
echo "       php tools/lint.php && php tests/run.php\n";
echo "       php tools/verify-openapi.php\n";
echo "       (apply migrations.sql, then the invariant + concurrency probes)\n";
echo "     See database/migrations/README.md and docs/DATABASE.md §10.\n";

// ── 1. Run the migrations ────────────────────────────────────────────────────
$files = glob($migrationDir . '/*.php') ?: [];
sort($files);

assertTrue($files !== [], 'No migration files found in ' . $migrationDir);

echo "\n[1] Executing migrations\n";

foreach ($files as $file) {
    $migration = require $file;

    if (! $migration instanceof Illuminate\Database\Migrations\Migration) {
        throw new RuntimeException('Migration does not return a Migration instance: ' . basename($file));
    }

    $migration->up();

    printf("  \u{2713} %s\n", basename($file, '.php'));
}

$recorder = Schema::recorder();
$tables = $recorder->tables;

printf("\n  %d tables declared\n", count($tables));

// ── 2. Structural checks ─────────────────────────────────────────────────────
echo "\n[2] Structure\n";

$expectedTables = [
    // identity & tenancy
    'organizations', 'users', 'roles', 'permissions', 'role_permissions', 'user_roles',
    'user_sessions', 'login_logs', 'audit_logs', 'settings', 'modules',
    // media (created early for FK availability)
    'media',
    // venues & schemas
    'venues', 'halls', 'hall_schemas', 'hall_schema_versions', 'sectors', 'rows', 'seats',
    'tables', 'standing_zones', 'schema_objects',
    // events & sessions
    'event_categories', 'events', 'event_translations', 'event_media', 'event_sessions',
    // inventory
    'inventory_items', 'seat_holds',
    // commerce
    'promo_codes', 'carts', 'cart_items', 'orders', 'order_items', 'promo_code_usages',
    // payments & tickets
    'idempotency_keys', 'payments', 'payment_transactions', 'refunds',
    'ticket_templates', 'tickets', 'checkin_devices', 'ticket_scans', 'offline_bundles',
    // supporting
    'notification_templates', 'notifications', 'webhooks', 'webhook_deliveries',
    'outbox_messages', 'pages', 'redirects', 'seo_meta', 'translations',
    'analytics_events', 'ab_tests', 'ab_test_variants', 'ab_assignments', 'heatmap_events',
    'ai_providers', 'ai_requests', 'api_keys', 'api_logs', 'ip_rules',
    'privacy_consents', 'data_requests', 'backups',
];

check('every expected table is declared', function () use ($tables, $expectedTables): void {
    $missing = array_values(array_diff($expectedTables, array_keys($tables)));
    assertTrue($missing === [], 'Missing tables: ' . implode(', ', $missing));
});

check('every foreign key points at a table that exists', function () use ($tables, $recorder): void {
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        foreach ($blueprint->foreignKeys as $fk) {
            if ($fk->table === '') {
                continue; // declared but target set later via ->on()
            }
            if (! isset($tables[$fk->table])) {
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
        $columns = array_map(static fn ($c) => $c->name, $blueprint->columns);

        $fks = $blueprint->foreignKeys;
        foreach ($recorder->addedForeignKeys[$name] ?? [] as $fk) {
            $fks[] = $fk;
        }

        foreach ($fks as $fk) {
            if (! in_array($fk->column, $columns, true)) {
                $problems[] = sprintf('%s.%s is referenced by a FK but the column is not declared', $name, $fk->column);
            }
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('every index column exists on its table', function () use ($tables): void {
    $problems = [];

    foreach ($tables as $name => $blueprint) {
        $columns = array_map(static fn ($c) => $c->name, $blueprint->columns);

        $indexes = $blueprint->indexes;
        foreach ($blueprint->columns as $col) {
            foreach ($col->indexes as $idx) {
                $indexes[] = ['columns' => [$col->name], 'type' => $idx['type'], 'name' => $idx['name']];
            }
        }

        foreach ($indexes as $index) {
            foreach ($index['columns'] as $column) {
                if (! in_array($column, $columns, true)) {
                    $problems[] = sprintf('%s: index on unknown column "%s"', $name, $column);
                }
            }
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

check('no duplicate index names across the schema', function () use ($tables): void {
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
            if (str_starts_with($indexName, 'auto:')) {
                continue;
            }
            if (isset($seen[$indexName])) {
                $problems[] = sprintf('index name "%s" used by both %s and %s', $indexName, $seen[$indexName], $name);
            }
            $seen[$indexName] = $name;
        }
    }

    assertTrue($problems === [], implode("\n      ", $problems));
});

// ── 3. Build SQLite and execute ──────────────────────────────────────────────
echo "\n[3] DDL execution (SQLite)\n";

if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    // Not every PHP build ships pdo_sqlite (this sandbox's does not). Sections 1
    // and 2 already proved the structure; skip the execution step with a clear
    // notice instead of dying with a raw PDOException.
    echo "  skip  pdo_sqlite is not available in this PHP build\n";
    echo "        (drivers present: " . implode(', ', PDO::getAvailableDrivers()) . ")\n";
    echo "        structural checks above still apply; run the MySQL probes for the\n";
    echo "        authoritative execution test.\n";

    // Section 3 and 4 both need the SQLite handle, so both are skipped; the summary
    // still needs the counter to exist.
    $indexCount = 0;

    goto summary;
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = ON');

/**
 * @param list<array{columns: list<string>, type: string, name: string|null}> $indexes
 */
function sqliteType(Illuminate\Database\Schema\ColumnDefinition $col): string
{
    return match ($col->type) {
        'id' => 'INTEGER',
        'string', 'text' => 'TEXT',
        'integer', 'boolean' => 'INTEGER',
        'decimal' => 'NUMERIC',
        'json' => 'TEXT',
        'datetime' => 'DATETIME',
        'enum' => 'TEXT',
        default => 'TEXT',
    };
}

$ddlStatements = [];

foreach ($tables as $name => $blueprint) {
    $defs = [];
    $tableConstraints = [];

    foreach ($blueprint->columns as $col) {
        if ($col->type === 'id') {
            $defs[] = sprintf('"%s" INTEGER PRIMARY KEY AUTOINCREMENT', $col->name);

            continue;
        }

        $sql = sprintf('"%s" %s', $col->name, sqliteType($col));

        if ($col->enumValues !== []) {
            $quoted = implode(', ', array_map(static fn (string $v): string => "'" . $v . "'", $col->enumValues));
            $sql .= sprintf(' CHECK ("%s" IN (%s))', $col->name, $quoted);
        }

        if (! $col->isNullable) {
            $sql .= ' NOT NULL';
        }

        if ($col->hasDefault) {
            $sql .= ' DEFAULT ' . $col->default;
        }

        $defs[] = $sql;
    }

    if ($blueprint->primary !== []) {
        $tableConstraints[] = sprintf(
            'PRIMARY KEY (%s)',
            implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $blueprint->primary))
        );
    }

    foreach ($blueprint->foreignKeys as $fk) {
        if ($fk->table === '') {
            continue;
        }
        $tableConstraints[] = sprintf(
            'FOREIGN KEY ("%s") REFERENCES "%s" ("%s") ON DELETE %s',
            $fk->column,
            $fk->table,
            $fk->references ?? 'id',
            $fk->onDelete
        );
    }

    $ddlStatements[] = sprintf(
        "CREATE TABLE \"%s\" (\n  %s\n)",
        $name,
        implode(",\n  ", array_merge($defs, $tableConstraints))
    );
}

// Deferred foreign keys (migration 000700) arrive as ALTER statements.
foreach ($recorder->addedForeignKeys as $table => $fks) {
    foreach ($fks as $fk) {
        $ddlStatements[] = sprintf(
            'ALTER TABLE "%s" ADD COLUMN "_fk_%s" INTEGER',
            $table,
            $fk->column
        );
    }
}

foreach ($ddlStatements as $ddl) {
    $pdo->exec($ddl);
}

check('all tables created in SQLite', function () use ($pdo, $tables): void {
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff(array_keys($tables), $rows));
    assertTrue($missing === [], 'Not created: ' . implode(', ', $missing));
});

// Unique indexes as separate statements: this matches MySQL semantics where NULLs
// are distinct, which is exactly what the one-active-hold trick relies on.
$indexCount = 0;
foreach ($tables as $name => $blueprint) {
    $indexes = $blueprint->indexes;

    foreach ($blueprint->columns as $col) {
        foreach ($col->indexes as $idx) {
            $indexes[] = ['columns' => [$col->name], 'type' => $idx['type'], 'name' => $idx['name']];
        }
    }

    foreach ($indexes as $i => $index) {
        $unique = $index['type'] === 'unique' ? 'UNIQUE ' : '';
        $indexName = $index['name'] ?? sprintf('%s_%s_%s', $name, $index['type'], implode('_', $index['columns']));

        $pdo->exec(sprintf(
            'CREATE %sINDEX "%s" ON "%s" (%s)',
            $unique,
            $indexName,
            $name,
            implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $index['columns']))
        ));
        $indexCount++;
    }
}

printf("  %d indexes created\n", $indexCount);

// ── 4. Seed minimal rows for invariant testing ───────────────────────────────
$pdo->exec("INSERT INTO organizations (id, name, slug) VALUES (1, 'Org', 'org')");
$pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Ivan', 'ivan@example.com')");
$pdo->exec("INSERT INTO venues (id, organization_id, name, slug) VALUES (1, 1, 'Crocus', 'crocus')");
$pdo->exec("INSERT INTO halls (id, organization_id, venue_id, name) VALUES (1, 1, 1, 'Big Hall')");
$pdo->exec("INSERT INTO hall_schemas (id, organization_id, hall_id) VALUES (1, 1, 1)");
$pdo->exec("INSERT INTO hall_schema_versions (id, organization_id, hall_schema_id, version_number) VALUES (1, 1, 1, 1)");
$pdo->exec("INSERT INTO events (id, organization_id, title, slug) VALUES (1, 1, 'Hamlet', 'hamlet')");
$pdo->exec(
    "INSERT INTO event_sessions (id, organization_id, event_id, venue_id, hall_id, hall_schema_version_id, starts_at)
     VALUES (1, 1, 1, 1, 1, 1, '2026-09-20 19:00:00')"
);

// ── 5. THE INVARIANTS ────────────────────────────────────────────────────────
echo "\n[4] Commercial invariants\n";

check('one session cannot contain the same seat twice (inventory_key)', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO inventory_items (id, organization_id, event_session_id, hall_schema_version_id, inventory_key)
         VALUES (10, 1, 1, 1, 'seat:101')"
    );

    try {
        $pdo->exec(
            "INSERT INTO inventory_items (id, organization_id, event_session_id, hall_schema_version_id, inventory_key)
             VALUES (11, 1, 1, 1, 'seat:101')"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation, got: ' . $e->getMessage());

        return;
    }

    throw new RuntimeException('a duplicate seat was accepted into the same session');
});

check('the same seat CAN exist in a different session (per-session inventory)', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO event_sessions (id, organization_id, event_id, venue_id, hall_id, hall_schema_version_id, starts_at)
         VALUES (2, 1, 1, 1, 1, 1, '2026-09-21 19:00:00')"
    );
    $pdo->exec(
        "INSERT INTO inventory_items (id, organization_id, event_session_id, hall_schema_version_id, inventory_key)
         VALUES (12, 1, 2, 1, 'seat:101')"
    );
    assertTrue(true, 'seat:101 exists independently in session 2');
});

check('only ONE active hold per inventory item is allowed', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO seat_holds (id, organization_id, event_session_id, inventory_item_id, expires_at, is_active)
         VALUES (100, 1, 1, 10, '2026-09-20 18:10:00', 1)"
    );

    try {
        $pdo->exec(
            "INSERT INTO seat_holds (id, organization_id, event_session_id, inventory_item_id, expires_at, is_active)
             VALUES (101, 1, 1, 10, '2026-09-20 18:20:00', 1)"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation');

        return;
    }

    throw new RuntimeException('a second ACTIVE hold was accepted for the same seat');
});

check('many CLOSED holds per inventory item are allowed (NULL is distinct)', function () use ($pdo): void {
    // Closing a hold sets is_active to NULL, which MySQL/SQLite treat as distinct in
    // a unique index. This is what makes the partial-index emulation work.
    $pdo->exec("UPDATE seat_holds SET is_active = NULL, status = 'expired' WHERE id = 100");

    $pdo->exec(
        "INSERT INTO seat_holds (id, organization_id, event_session_id, inventory_item_id, expires_at, is_active)
         VALUES (102, 1, 1, 10, '2026-09-20 18:30:00', NULL)"
    );
    $pdo->exec(
        "INSERT INTO seat_holds (id, organization_id, event_session_id, inventory_item_id, expires_at, is_active)
         VALUES (103, 1, 1, 10, '2026-09-20 18:40:00', 1)"
    );

    $count = (int) $pdo->query("SELECT COUNT(*) FROM seat_holds WHERE inventory_item_id = 10")->fetchColumn();
    assertTrue($count === 3, 'expected 3 historical holds, got ' . $count);

    $active = (int) $pdo->query("SELECT COUNT(*) FROM seat_holds WHERE inventory_item_id = 10 AND is_active = 1")->fetchColumn();
    assertTrue($active === 1, 'expected exactly 1 active hold, got ' . $active);
});

check('a duplicate payment webhook event id is rejected (idempotency guard)', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO orders (id, organization_id, order_number, email)
         VALUES (1, 1, 'NB-2026-000001', 'ivan@example.com')"
    );
    $pdo->exec(
        "INSERT INTO payments (id, organization_id, order_id, amount_minor)
         VALUES (1, 1, 1, 5300)"
    );

    $pdo->exec(
        "INSERT INTO payment_transactions (id, payment_id, organization_id, type, provider_event_id)
         VALUES (1, 1, 1, 'webhook', 'evt_abc123')"
    );

    try {
        $pdo->exec(
            "INSERT INTO payment_transactions (id, payment_id, organization_id, type, provider_event_id)
             VALUES (2, 1, 1, 'webhook', 'evt_abc123')"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation on provider_event_id');

        return;
    }

    throw new RuntimeException('the same webhook event was accepted twice — replay protection is broken');
});

check('three identical webhooks still leave one processed event row', function () use ($pdo): void {
    $accepted = 0;
    for ($i = 0; $i < 3; $i++) {
        try {
            $pdo->exec(
                "INSERT INTO payment_transactions (id, payment_id, organization_id, type, provider_event_id)
                 VALUES (" . (10 + $i) . ", 1, 1, 'webhook', 'evt_abc123')"
            );
            $accepted++;
        } catch (PDOException) {
            // expected for attempts 2 and 3
        }
    }

    assertTrue($accepted === 0, 'expected all replays to be refused, ' . $accepted . ' were accepted');
});

check('one ticket per order item (issuance idempotency)', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO order_items (id, order_id, organization_id, inventory_item_id, event_session_id, unit_price_minor, total_minor)
         VALUES (1, 1, 1, 10, 1, 5000, 5000)"
    );

    $pdo->exec(
        "INSERT INTO tickets (id, organization_id, ticket_number, order_id, order_item_id, event_id,
                              event_session_id, inventory_item_id, qr_token, qr_payload)
         VALUES (1, 1, 'T-0001', 1, 1, 1, 1, 10, 'token-aaa', 'NB1.1.token-aaa.sig')"
    );

    try {
        $pdo->exec(
            "INSERT INTO tickets (id, organization_id, ticket_number, order_id, order_item_id, event_id,
                                  event_session_id, inventory_item_id, qr_token, qr_payload)
             VALUES (2, 1, 'T-0002', 1, 1, 1, 1, 10, 'token-bbb', 'NB1.1.token-bbb.sig')"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation on order_item_id');

        return;
    }

    throw new RuntimeException('a second ticket was issued for the same order item — double issuance possible');
});

check('a QR token cannot be reused across tickets', function () use ($pdo): void {
    try {
        $pdo->exec(
            "INSERT INTO tickets (id, organization_id, ticket_number, order_id, order_item_id, event_id,
                                  event_session_id, inventory_item_id, qr_token, qr_payload)
             VALUES (3, 1, 'T-0003', 1, 999, 1, 1, 12, 'token-aaa', 'NB1.1.token-aaa.sig')"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation on qr_token');

        return;
    }

    throw new RuntimeException('a QR token was reused');
});

check('a promo code cannot be redeemed twice on one order', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO promo_codes (id, organization_id, code, type, value)
         VALUES (1, 1, 'WELCOME10', 'percent', 1000)"
    );
    $pdo->exec(
        "INSERT INTO promo_code_usages (id, promo_code_id, order_id, organization_id, discount_minor)
         VALUES (1, 1, 1, 1, 530)"
    );

    try {
        $pdo->exec(
            "INSERT INTO promo_code_usages (id, promo_code_id, order_id, organization_id, discount_minor)
             VALUES (2, 1, 1, 1, 530)"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation');

        return;
    }

    throw new RuntimeException('the same promo code was redeemed twice on one order');
});

check('one seat cannot appear twice in one order', function () use ($pdo): void {
    try {
        $pdo->exec(
            "INSERT INTO order_items (id, order_id, organization_id, inventory_item_id, event_session_id, unit_price_minor, total_minor)
             VALUES (2, 1, 1, 10, 1, 5000, 5000)"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation on (order_id, inventory_item_id)');

        return;
    }

    throw new RuntimeException('the same inventory item was added twice to one order');
});

check('one seat cannot appear twice in one cart', function () use ($pdo): void {
    $pdo->exec(
        "INSERT INTO carts (id, organization_id, event_session_id) VALUES (1, 1, 1)"
    );
    $pdo->exec(
        "INSERT INTO cart_items (id, cart_id, inventory_item_id, unit_price_minor, total_minor)
         VALUES (1, 1, 10, 5000, 5000)"
    );

    try {
        $pdo->exec(
            "INSERT INTO cart_items (id, cart_id, inventory_item_id, unit_price_minor, total_minor)
             VALUES (2, 1, 10, 5000, 5000)"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation');

        return;
    }

    throw new RuntimeException('the same seat was added twice to one cart');
});

check('two sessions of the same event cannot start in the same hall at the same time', function () use ($pdo): void {
    try {
        $pdo->exec(
            "INSERT INTO event_sessions (id, organization_id, event_id, venue_id, hall_id, hall_schema_version_id, starts_at)
             VALUES (9, 1, 1, 1, 1, 1, '2026-09-20 19:00:00')"
        );
    } catch (PDOException $e) {
        assertTrue(str_contains($e->getMessage(), 'UNIQUE'), 'expected a UNIQUE violation on (hall_id, starts_at)');

        return;
    }

    throw new RuntimeException('two overlapping sessions were accepted in the same hall');
});

check('sales history is protected: an organization with venues cannot be deleted', function () use ($pdo): void {
    try {
        $pdo->exec('DELETE FROM organizations WHERE id = 1');
    } catch (PDOException $e) {
        assertTrue(
            str_contains($e->getMessage(), 'FOREIGN KEY') || str_contains($e->getMessage(), 'constraint'),
            'expected a FK violation, got: ' . $e->getMessage()
        );

        return;
    }

    throw new RuntimeException('deleting an organization cascaded away business data — RESTRICT is not in effect');
});

check('a paid order cannot be deleted while payments reference it', function () use ($pdo): void {
    try {
        $pdo->exec('DELETE FROM orders WHERE id = 1');
    } catch (PDOException $e) {
        assertTrue(
            str_contains($e->getMessage(), 'FOREIGN KEY') || str_contains($e->getMessage(), 'constraint'),
            'expected a FK violation'
        );

        return;
    }

    throw new RuntimeException('deleting an order destroyed its payment records');
});

// ─────────────────────────────────────────────────────────────────────────────
summary:
echo "\n", str_repeat('─', 74), "\n";

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $i => $failure) {
        printf("  %2d) %s\n", $i + 1, $failure);
    }
    echo "\n";
}

printf(
    "%s  %d checks passed, %d failed  (%d tables, %d indexes)\n\n",
    $failed === 0 ? 'PASS' : 'FAIL',
    $passed,
    $failed,
    count($tables),
    $indexCount
);

exit($failed === 0 ? 0 : 1);
