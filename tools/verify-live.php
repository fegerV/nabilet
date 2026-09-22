<?php

declare(strict_types=1);

/**
 * NABILET Core — live-environment checks
 * =====================================================================
 * The other verifiers in tools/ read the repository: migration files,
 * composer.json, the spec bundle. They are CI ratchets and they pass on a
 * checkout that cannot actually serve a request.
 *
 * This one reads the RUNNING system — the booted application, the connected
 * database, the registered route table — and answers two questions the file
 * readers cannot:
 *
 *   [1] Does the live database match the spec, including the invariants that
 *       only exist if a migration really executed (CHECK constraints, triggers)?
 *   [2] Does the app serve the paths the spec declares?
 *
 * Both were green-on-paper and false-in-fact on 2026-09-22:
 *   - the migrations are guarded to mysql/mariadb, the server ran pgsql, so all
 *     35 CHECK constraints and the trigger were absent while `migrate:status`
 *     reported every migration as "Ran";
 *   - 53 API routes carried a second version prefix (/api/v1/v1/events), so only
 *     3 of the spec's 81 paths were reachable.
 *
 * NOT part of the CI gate: it needs a live database and a bootable app. Run it
 * wherever the application actually runs.
 *
 *   php tools/verify-live.php
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$failed = false;
$line = static fn(string $s = ''): string => $s . "\n";

echo $line();
echo $line('NABILET Core — live environment');
echo $line('──────────────────────────────────────────────────────────────────────────');

$driver = DB::connection()->getDriverName();
echo $line("  Driver: {$driver}");

/* ------------------------------------------------------------------ *
 * [1] Live schema vs spec
 * ------------------------------------------------------------------ */

echo $line();
echo $line('[1] Database vs spec bundle');

$spec = file_get_contents($root . '/nabilet_core_spec/migrations.sql');

/**
 * Spec columns, from CREATE TABLE and ALTER TABLE.
 *
 * ALTER is not optional. orders.promo_code_id, tickets.revoked_at and
 * tickets.revoked_reason appear in no CREATE block, so a CREATE-only reader
 * reports three spec columns as drift. And one ALTER can carry several
 * comma-continued clauses, so the whole statement body must be scanned.
 */
$specTables = [];
$specCols = [];

if (preg_match_all(
    '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE/is',
    $spec,
    $blocks,
    PREG_SET_ORDER
)) {
    foreach ($blocks as $b) {
        $t = strtolower($b[1]);
        $specTables[$t] = true;
        $body = preg_replace('/^\s*(PRIMARY|UNIQUE|KEY|CONSTRAINT|INDEX|FOREIGN|CHECK)\b.*$/im', '', $b[2]);
        foreach (preg_split('/\r?\n/', $body) as $l) {
            if (preg_match('/^\s*`?(\w+)`?\s+[A-Za-z]/', $l, $c)) {
                $specCols[$t . '.' . strtolower($c[1])] = true;
            }
        }
    }
}

if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+(.*?);/is', $spec, $stmts, PREG_SET_ORDER)) {
    foreach ($stmts as $s) {
        $t = strtolower($s[1]);
        if (! preg_match_all('/\bADD\s+(?:COLUMN\s+)?`?(\w+)`?/i', $s[2], $cols, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($cols as $c) {
            $col = strtolower($c[1]);
            // ADD CONSTRAINT / ADD KEY also match "ADD <word>"; they are not columns.
            if (in_array($col, ['constraint', 'key', 'index', 'primary', 'unique', 'foreign', 'check'], true)) {
                continue;
            }
            $specCols[$t . '.' . $col] = true;
        }
    }
}

// Self-check before trusting anything below. An under-reading parser invents
// drift, and invented drift gets frozen into an accepted-drift list.
$expectedTables = 64;
$expectedColumns = 678;
if (count($specTables) !== $expectedTables || count($specCols) !== $expectedColumns) {
    fwrite(\STDERR, $line(sprintf(
        '  FAIL  spec parser read %d tables / %d columns, expected %d / %d.'
        . "\n        Fix the parser before trusting its verdict.\n",
        count($specTables),
        count($specCols),
        $expectedTables,
        $expectedColumns
    )));
    exit(1);
}
echo $line(sprintf('  spec parsed: %d tables, %d columns (self-check ok)', count($specTables), count($specCols)));

// Live counts. Postgres reports every NOT NULL column as a CHECK constraint in
// information_schema, which inflates 35 into hundreds; pg_constraint is the
// honest source.
if ($driver === 'pgsql') {
    $live = [
        'fk' => (int) DB::selectOne("SELECT count(*) c FROM pg_constraint x
            JOIN pg_namespace n ON n.oid=x.connamespace WHERE n.nspname='public' AND x.contype='f'")->c,
        'unique' => (int) DB::selectOne("SELECT count(*) c FROM pg_constraint x
            JOIN pg_namespace n ON n.oid=x.connamespace WHERE n.nspname='public' AND x.contype='u'")->c,
        'check' => (int) DB::selectOne("SELECT count(*) c FROM pg_constraint x
            JOIN pg_namespace n ON n.oid=x.connamespace WHERE n.nspname='public' AND x.contype='c'")->c,
        'trigger' => (int) DB::selectOne("SELECT count(*) c FROM pg_trigger t
            JOIN pg_class cl ON cl.oid=t.tgrelid
            JOIN pg_namespace n ON n.oid=cl.relnamespace
            WHERE n.nspname='public' AND NOT t.tgisinternal")->c,
    ];
} else {
    $live = [
        'fk' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.table_constraints
            WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY'")->c,
        'unique' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.table_constraints
            WHERE constraint_schema=DATABASE() AND constraint_type='UNIQUE'")->c,
        'check' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.table_constraints
            WHERE constraint_schema=DATABASE() AND constraint_type='CHECK'")->c,
        'trigger' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.triggers
            WHERE trigger_schema=DATABASE()")->c,
    ];
}

$expect = ['fk' => 93, 'unique' => 81, 'check' => 35, 'trigger' => 1];
foreach ($expect as $k => $want) {
    $got = $live[$k];
    $ok = $got === $want;
    if (! $ok) {
        $failed = true;
    }
    echo $line(sprintf('  %-8s live %4d   spec %4d   %s', $k, $got, $want, $ok ? 'ok' : 'MISMATCH'));
}

if ($live['check'] === 0 && $expect['check'] > 0) {
    echo $line();
    echo $line('  ! The database enforces none of the spec\'s CHECK constraints.');
    echo $line('    The migrations that add them are guarded to mysql/mariadb and');
    echo $line('    return early elsewhere, so `migrate:status` still says "Ran".');
    echo $line('    Status green does not mean the invariant exists.');
}

// Column-level diff.
$rows = DB::select("SELECT table_name, column_name FROM information_schema.columns
                    WHERE table_schema=" . ($driver === 'pgsql' ? "'public'" : 'DATABASE()'));
$liveCols = [];
foreach ($rows as $r) {
    // Laravel's own bookkeeping table is not part of the domain schema.
    if (strtolower($r->table_name) === 'migrations') {
        continue;
    }
    $liveCols[strtolower($r->table_name) . '.' . strtolower($r->column_name)] = true;
}

$extraCols = [];
foreach ($liveCols as $k => $_) {
    if (! isset($specCols[$k])) {
        $extraCols[] = $k;
    }
}
$missingCols = [];
foreach ($specCols as $k => $_) {
    if (! isset($liveCols[$k])) {
        $missingCols[] = $k;
    }
}

echo $line();
echo $line(sprintf('  columns: %d live, %d spec', count($liveCols), count($specCols)));
foreach ($extraCols as $c) {
    echo $line("    EXTRA    $c   (not in the spec bundle)");
}
foreach ($missingCols as $c) {
    $failed = true;
    echo $line("    MISSING  $c");
}
if ($extraCols === [] && $missingCols === []) {
    echo $line('    no column drift');
}

/* ------------------------------------------------------------------ *
 * [2] Registered routes vs spec paths
 * ------------------------------------------------------------------ */

echo $line();
echo $line('[2] API routes vs spec paths');

$openapi = file_get_contents($root . '/nabilet_core_spec/openapi.yaml');
$norm = static function (string $uri): string {
    $uri = '/' . trim($uri, '/');
    $uri = preg_replace('/\{[^}]+\}/', '{}', $uri);

    return rtrim($uri, '/') ?: '/';
};

$specPaths = [];
if (preg_match_all('/^ {2}(\/\S*):\s*$/m', $openapi, $pm)) {
    foreach ($pm[1] as $p) {
        $specPaths[$norm($p)] = true;
    }
}

$appPaths = [];
$doubled = [];
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }
    $n = $norm($uri);
    $appPaths[$n] = true;
    if (str_contains($n, '/v1/v1/') || str_contains($n, '/api/v1/api/v1/')) {
        $doubled[$n] = true;
    }
}

$missing = array_diff_key($specPaths, $appPaths);
$served = array_intersect_key($specPaths, $appPaths);

echo $line(sprintf('  spec paths %d | app api routes %d | served as specified %d | missing %d',
    count($specPaths), count($appPaths), count($served), count($missing)));

if ($doubled !== []) {
    $failed = true;
    echo $line();
    echo $line('  Routes carrying a second version prefix (unreachable at the spec path):');
    foreach (array_keys($doubled) as $d) {
        echo $line("    $d");
    }
    echo $line('  The global apiPrefix in bootstrap/app.php already mounts these at');
    echo $line('  /api/v1. A module route file must not repeat the version segment.');
}

if ($missing !== []) {
    echo $line();
    echo $line(sprintf('  %d spec path(s) not served:', count($missing)));
    foreach (array_keys($missing) as $m) {
        echo $line("    $m");
    }
    echo $line();
    echo $line('  Reported, not failed: this gap is a roadmap item (admin/, me/, embed/,');
    echo $line('  checkin/, media/, promo-codes/, translations namespaces are unbuilt).');
    echo $line('  See docs/REVIEW-spec-bundle.md.');
}

/* ------------------------------------------------------------------ */

echo $line();
echo $line('──────────────────────────────────────────────────────────────────────────');
if ($failed) {
    echo $line('FAIL — the live system does not match the spec.');
    echo $line();
    exit(1);
}

echo $line('PASS — the live database and route table match the spec.');
echo $line();

exit(0);
