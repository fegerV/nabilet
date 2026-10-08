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
 *   - every migration that adds a CHECK constraint or the trigger is guarded by
 *     driver, and returned early on the engine the server was actually running,
 *     so all 35 CHECK constraints and the trigger were absent while
 *     `migrate:status` reported every migration as "Ran";
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
//
// ВНИМАНИЕ: те же два числа продублированы в `tools/verify-models-schema.php`
// (`EXPECTED_TABLES` / `EXPECTED_COLUMNS`) и `tools/verify-contract-schema.php`.
// Три скрипта, одно число, три места правки — и это уже стреляло: после того
// как в спеку переехали 9 таблиц домена (64→73 таблицы, 678→785 колонок),
// обновили два файла из трёх, и этот начал падать с «Fix the parser before
// trusting its verdict». Меняйте все три сразу.
$expectedTables = 73;
$expectedColumns = 785;
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

// Live counts, from MySQL's own bookkeeping. `constraint_schema=DATABASE()` keeps
// it to this database: a shared host puts other tenants' schemas in the same
// server, and counting those would make the numbers meaningless.
//
// App-only extensions are excluded from the live counts. They are deliberate:
// `tools/verify-migrations.php` records the same four in `$knownExtensions`, and
// they exist because Laravel needs its own plumbing (queue, failed jobs, token
// storage) plus Filament's "remember me". Without this exclusion the tool
// reported `personal_access_tokens_token_unique` and `failed_jobs_uuid_unique`
// as drift, and 25 of those tables' columns as "EXTRA" — noise that trains the
// reader to ignore the section, which is how a real MISSING line gets missed.
$appOnlyTables = ['failed_jobs', 'jobs', 'personal_access_tokens'];
$appOnlyExclusion = "'" . implode("','", $appOnlyTables) . "'";

$live = [
    'fk' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.table_constraints
        WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY'
          AND table_name NOT IN ({$appOnlyExclusion})")->c,
    'unique' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.table_constraints
        WHERE constraint_schema=DATABASE() AND constraint_type='UNIQUE'
          AND table_name NOT IN ({$appOnlyExclusion})")->c,
    'check' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.table_constraints
        WHERE constraint_schema=DATABASE() AND constraint_type='CHECK'
          AND table_name NOT IN ({$appOnlyExclusion})")->c,
    // Триггеры считаем по объекту, а не по имени таблицы: все три живут на
    // spec-таблицах (`hall_schema_versions`, `halls`), поэтому исключение
    // app-only таблиц здесь ничего не отфильтрует. Расхождение по триггерам
    // разбирается отдельно ниже — оно не про app-only расширения.
    'trigger' => (int) DB::selectOne("SELECT count(*) c FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()")->c,
];

// Числа сверены с самой спекой, а не подобраны: `grep -c 'FOREIGN KEY'` по
// `nabilet_core_spec/migrations.sql` даёт 101, уникальных ключей — 88 (88 в
// спеке + 2 у app-only таблиц = 90 живых). CHECK — 35: тридцать шестое
// вхождение `CHECK (` в спеке находится не в описании таблицы, а в теле
// триггера, поэтому констрейнтом не становится.
//
// Триггеры в этот цикл НЕ входят: они сравниваются отдельно ниже, потому что
// их расхождение — отставшая спека, а не дрейф схемы, и оно не должно
// сбрасываться или подгоняться вместе с остальными счётчиками.
$expect = ['fk' => 101, 'unique' => 88, 'check' => 35];
foreach ($expect as $k => $want) {
    $got = $live[$k];
    $ok = $got === $want;
    if (! $ok) {
        $failed = true;
    }
    echo $line(sprintf('  %-8s live %4d   spec %4d   %s', $k, $got, $want, $ok ? 'ok' : 'MISMATCH'));
}

if ($live['trigger'] !== 1) {
    // Расхождение по триггерам — не app-only шум, а отставшая спека, и
    // записываем это явно, а не подгонкой числа. Миграции создают три
    // триггера жизненного цикла схемы зала и УДАЛЯЮТ тот единственный, что
    // описан в спеке (`trg_schema_version_immutable`). То есть спека
    // описывает устаревшую защиту и не описывает три действующие, которые и
    // обеспечивают инвариант неизменяемости опубликованной схемы.
    // Подробности — `tools/verify-migrations.php`, проверка «hall lifecycle
    // triggers are emitted without a replacement gap».
    echo $line(sprintf('  %-8s live %4d   spec %4d   %s', 'trigger', $live['trigger'], 1, 'spec behind'));
    echo $line('  Триггеры: спека описывает один (trg_schema_version_immutable),');
    echo $line('  миграции создают три и удаляют описанный. Живые триггеры:');

    foreach (DB::select('SELECT trigger_name, event_object_table FROM information_schema.triggers
                         WHERE trigger_schema=DATABASE()') as $t) {
        echo $line("    {$t->TRIGGER_NAME}  on {$t->EVENT_OBJECT_TABLE}");
    }

    echo $line('  Reported, not failed: спеку нужно привести к трём триггерам');
    echo $line('  жизненного цикла — это отдельная правка спеки, а не дрейф схемы.');
} else {
    echo $line(sprintf('  %-8s live %4d   spec %4d   ok', 'trigger', $live['trigger'], 1));
}

if ($live['check'] === 0 && $expect['check'] > 0) {
    echo $line();
    echo $line('  ! The database enforces none of the spec\'s CHECK constraints.');
    echo $line('    The migrations that add them are guarded by driver and return');
    echo $line('    early when the guard does not match, so `migrate:status` still');
    echo $line('    says "Ran". Status green does not mean the invariant exists.');
}

// Column-level diff.
//
// MySQL 8 отдаёт имена колонок `information_schema` в ВЕРХНЕМ регистре
// (`TABLE_NAME`), независимо от того, как они написаны в SELECT — проверено
// на этом сервере. Обращение к `$r->table_name` падало с «Undefined property».
// Раньше это не проявлялось: скрипт выходил на self-check выше и до этой
// строки не доходил. То есть устаревшая константа не просто мешала вердикту —
// она прятала падение за ним.
$rows = DB::select('SELECT table_name, column_name FROM information_schema.columns
                    WHERE table_schema=DATABASE()');
$liveCols = [];
foreach ($rows as $r) {
    // Laravel's own bookkeeping table is not part of the domain schema.
    if (strtolower($r->TABLE_NAME) === 'migrations') {
        continue;
    }

    // App-only tables (см. `$appOnlyTables` выше) в дрейф не идут: их
    // отсутствие в спеке — решение, а не расхождение. Раньше 25 их колонок
    // печатались как EXTRA и забивали вывод.
    if (in_array(strtolower($r->TABLE_NAME), $appOnlyTables, true)) {
        continue;
    }

    $liveCols[strtolower($r->TABLE_NAME) . '.' . strtolower($r->COLUMN_NAME)] = true;
}

// `users.remember_token` — четвёртое app-only расширение (его добавляет
// Filament для «remember me»); записано в `$knownExtensions` в
// `tools/verify-migrations.php`.
unset($liveCols['users.remember_token']);

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
