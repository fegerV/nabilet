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
require $root . '/tools/spec-counts.php';

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
// ВНИМАНИЕ: числа больше НЕ живут в этом файле. Они в `tools/spec-counts.php` —
// единственном месте, которое читают все три инструмента (`verify-live`,
// `verify-models-schema`, `verify-contract-schema`). Здесь они дублировались, и
// это стреляло трижды: после сплитов 016 и 018 обновляли два файла из трёх, а
// после 023 (`c581afbd`) — снова два из трёх, и этот падал на первой проверке с
// «Fix the parser before trusting its verdict», обвиняя исправный парсер.
//
// История ниже — как росли числа ДО выноса (до 85/881). Дальше её ведёт
// `spec-counts.php`: 85/881 → 88/911 (таблицы `artists`, `artist_event`,
// `newsletter_subscribers`).
//
// 785 → 786: сплит `016_login_logs_event.sql` добавил `login_logs.event`
// (аудит подбора пароля писал событие `login_failed` без колонки, куда его
// положить). Тогда же обновили два других файла — этот остался на 785, то есть
// ровно тот же сбой повторился второй раз. `017_status_vocabularies.sql`
// колонок не добавляет (только CHECK), поэтому число не менялось.
//
// 786 → 809: сплит `018_loyalty_foundation.sql` добавил три таблицы «Клуба
// зрителей» (`event_favorites`, `event_follows`, `follow_notifications`) —
// фаза 1 `docs/LOYALTY-PLAN.md`. Обновлены все три файла одновременно.
//
// 809 → 825: сплит `019_bonus_ledger.sql` (фаза 2) добавил две таблицы
// (`bonus_accounts` — 7 колонок, `bonus_transactions` — 8) и одну колонку в
// существующей (`orders.bonus_amount`). Итого 76 → 78 таблиц, 809 → 825 колонок.
//
// 825 → 834: сплит `020_loyalty_levels.sql` (фаза 3) добавил таблицу
// `loyalty_levels` (8 колонок) и одну колонку в существующей
// (`users.loyalty_level_id`). Итого 78 → 79 таблиц, 825 → 834 колонки.
//
// 834 → 849: сплит `021_referrals.sql` (фаза 4) добавил таблицы
// `referral_codes` (6 колонок) и `referrals` (9 колонок). Итого 79 → 81
// таблица, 834 → 849 колонок. Обновлены все три файла одновременно.
//
// 849 → 881: сплит `022_raffles.sql` (фаза 5) добавил таблицы `raffles` (10
// колонок), `raffle_prizes` (8), `raffle_entries` (6) и `raffle_winners` (8).
// Итого 81 → 85 таблиц, 849 → 881 колонка. Обновлены все четыре файла
// одновременно (ci.yml, verify-models-schema, verify-contract-schema,
// verify-live) плюс `docs/DATABASE.md`.
// Сначала — отстала ли сама константа. Проверка независимая: считает
// `CREATE TABLE` в бандле, не доверяя ни одному из трёх парсеров. Без неё
// отставшая константа выглядит как сломанный парсер (так и было трижды).
$countsProblem = spec_counts_problem($spec);
if ($countsProblem !== null) {
    fwrite(\STDERR, $line('  FAIL  ' . $countsProblem));
    exit(1);
}

$expectedTables = EXPECTED_TABLES;
$expectedColumns = EXPECTED_COLUMNS;
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
// storage, HTTP sessions) plus Filament's "remember me". Without this exclusion
// the tool reported `personal_access_tokens_token_unique` and
// `failed_jobs_uuid_unique` as drift, and 25 of those tables' columns as
// "EXTRA" — noise that trains the reader to ignore the section, which is how a
// real MISSING line gets missed.
//
// `web_sessions` был забыт в этом списке, хотя в `verify-migrations.php` он
// есть с самого появления (2026_10_12_000600). Следствие: шесть его колонок
// печатались как EXTRA, и раздел «columns» показывал 917 против 911 в спеке —
// расхождение, которого нет. Добавлен, чтобы список совпадал с единственным
// местом, которое его действительно проверяет.
$appOnlyTables = ['failed_jobs', 'jobs', 'personal_access_tokens', 'web_sessions'];
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

// Числа НЕ подобраны и НЕ сложены вручную — они измерены тем же инструментом,
// что и живые: бандл загружается в пустую схему, дальше `information_schema`.
// Так обе стороны сравнения считает один счётчик, и «ожидание» не может
// разойтись со спекой из-за арифметики.
//
// Перемерить после сплита миграций (≈10 секунд):
//   docker exec nabilet-mysql mysql -uroot -prootpass -e \
//     'DROP DATABASE IF EXISTS nbl_cnt; CREATE DATABASE nbl_cnt'
//   docker exec -i nabilet-mysql mysql -uroot -prootpass nbl_cnt \
//     < nabilet_core_spec/migrations.sql
//   docker exec nabilet-mysql mysql -uroot -prootpass -N -B -e \
//     "SELECT constraint_type, count(*) FROM information_schema.table_constraints
//      WHERE constraint_schema='nbl_cnt' GROUP BY constraint_type"
//
// ПОЧЕМУ ЭТО ВАЖНО. Раньше здесь стояла арифметика по сплитам, и она отстала
// на два сплита: 117/105/51 — это состояние после `021_referrals.sql`, тогда
// как бандл уже содержал 022 (розыгрыши) и 023 (артисты + лента подписки).
// Инструмент полгода падал на трёх MISMATCH, которых в схеме нет, и никто его
// не запускал. Ровно та же болезнь, что у константы в `spec-counts.php`:
// ручной счётчик, который выглядит авторитетным, но не пересчитывается.
//
// Состав чисел (измерено по information_schema, дельта от 021 → 023):
//   117 → 125, 105 → 113, 51 → 53  сплит `022_raffles.sql` (фаза 5).
//     Внешние ключи — 8: `fk_raffle_entries_{raffle,user,order}`,
//     `fk_raffle_prizes_raffle`, `fk_raffle_winners_{raffle,prize,entry,user}`.
//     Уникальные — 8: `uq_raffles_public_id`, `uq_raffle_prizes_{public_id,place}`,
//     `uq_raffle_entries_{public_id,order}`, `uq_raffle_winners_{public_id,prize,entry}`.
//     CHECK — 2: `ck_raffles_status`, `ck_raffle_prizes_place`.
//   125 → 127, 113 → 116, 53 → 53  мерж `c84e8688` (артисты, контент-модуль).
//     Внешние ключи — 2: `artist_event_{artist,event}_id_foreign`.
//     Уникальные — 3: `uq_artist_event`, `uq_artists_slug`, `uq_artists_normalized_name`.
//   127 → 127, 116 → 118, 53 → 53  сплит `023`/`c581afbd` (лента подписки).
//     Уникальные — 2: `uq_newsletter_email`, `uq_newsletter_unsub_token`.
//
// Триггеры в этот цикл НЕ входят: они сравниваются отдельно ниже, потому что
// их расхождение — отставшая спека, а не дрейф схемы, и оно не должно
// сбрасываться или подгоняться вместе с остальными счётчиками.
$expect = ['fk' => 127, 'unique' => 118, 'check' => 53];
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

// Собираем ВСЕ маршруты, а не только `api/*`. Спека объявляет два пути вне
// этого префикса (`/auth/yandex/callback`, `/auth/vk/callback`), и раньше они
// попадали в «missing» автоматически — даже если бы их реализовали как
// web-маршруты, фильтр `str_starts_with($uri, 'api/')` их не увидел бы. Гейт,
// который не может пройти корректная реализация, — неверный гейт.
$appPaths = [];
$doubled = [];
foreach (Route::getRoutes() as $route) {
    $n = $norm($route->uri());
    $appPaths[$n] = true;
    if (str_contains($n, '/v1/v1/') || str_contains($n, '/api/v1/api/v1/')) {
        $doubled[$n] = true;
    }
}
$appApiPaths = array_filter(
    array_keys($appPaths),
    static fn (string $p): bool => str_starts_with($p, '/api/')
);

$missing = array_diff_key($specPaths, $appPaths);
$served = array_intersect_key($specPaths, $appPaths);

// Три разных причины «не отдаётся» смешивались в одном списке, и из-за этого
// число 32 читалось как «32 недоделанных эндпоинта», хотя часть из них
// работает — просто под другим префиксом.
//
// Признак «другой префикс» — узкий и точный: путь приложения равен пути спеки
// с ОДНИМ вставленным сегментом сразу после `/api/v1`. Это ровно случай
// модуля Embed, который монтируется в `/api/v1/embed`:
//   спека  /api/v1/events/{}/sessions
//   app    /api/v1/embed/events/{}/sessions
//
// Почему не «совпал хвост пути». Первая версия сравнивала хвосты и объявляла
// `/api/v1/admin/tickets/{}/cancel` «обслуживаемым» через
// `/api/v1/events/{}/cancel`, а `/api/v1/admin/webhooks/{}` — через
// `/api/v1/payments/webhooks/{}`. Это разные эндпоинты с разной авторизацией и
// разным контрактом; такой «матч» хуже честного «отсутствует»: читатель
// вычёркивает строку из списка дел, которой там место.
//
// Почему не «app длиннее и заканчивается спекой». Вставка стоит в СЕРЕДИНЕ
// (`/api/v1/embed/...`), поэтому app не оканчивается на путь спеки — тест не
// находил ни одного совпадения и печатал «0 под другим префиксом» при трёх
// реальных.
$prefixed = [];
$absent = [];
$versionPrefix = '/api/v1/';
foreach (array_keys($missing) as $specPath) {
    $candidates = [];

    if (str_starts_with($specPath, $versionPrefix)) {
        $tail = substr($specPath, strlen($versionPrefix));
        $pattern = '#^' . preg_quote($versionPrefix, '#') . '[^/]+/' . preg_quote($tail, '#') . '$#';

        foreach ($appApiPaths as $appPath) {
            if (preg_match($pattern, $appPath) === 1) {
                $candidates[] = $appPath;
            }
        }
    }

    if ($candidates === []) {
        $absent[] = $specPath;
    } else {
        $prefixed[$specPath] = $candidates;
    }
}

echo $line(sprintf('  spec paths %d | app routes %d (%d under /api) | served as specified %d',
    count($specPaths), count($appPaths), count($appApiPaths), count($served)));
echo $line(sprintf('  missing %d = %d served under another prefix + %d absent',
    count($missing), count($prefixed), count($absent)));

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

if ($prefixed !== []) {
    echo $line();
    echo $line(sprintf('  %d spec path(s) served under a different prefix:', count($prefixed)));
    foreach ($prefixed as $specPath => $appPathsFor) {
        echo $line("    SPEC  $specPath");
        foreach ($appPathsFor as $a) {
            echo $line("      app $a");
        }
    }
    echo $line('  Not a gap: the endpoint exists and answers. Two ways to close it —');
    echo $line('  move the route to the spec path (breaking for existing embed clients)');
    echo $line('  or amend the spec. Pick one; do not leave the report ambiguous.');
}

if ($absent !== []) {
    echo $line();
    echo $line(sprintf('  %d spec path(s) absent:', count($absent)));
    foreach ($absent as $m) {
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
