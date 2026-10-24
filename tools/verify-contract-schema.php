<?php

declare(strict_types=1);

/**
 * Contract ↔ schema drift: does the API promise fields the database cannot store?
 *
 * Usage:
 *     php tools/verify-contract-schema.php
 *     php tools/verify-contract-schema.php nabilet_core_spec/openapi.yaml
 *
 * WHY THIS EXISTS
 *   Two artefacts describe the same world and were maintained separately. The
 *   OpenAPI contract and the DDL agree on almost everything, which is exactly
 *   why the places they disagree are hard to see: a missing column looks like
 *   every other column until you try to write it.
 *
 *   The motivating case was found by hand, not by a tool. `Ticket` declares
 *   `revoked_at` and `revoked_reason`; `tickets` has `used_at`, `cancelled_at`,
 *   `refunded_at`, `expired_at` — and no `revoked_at` at all. The contract
 *   therefore promises two fields the database cannot store, and the domain
 *   already depends on them (`TicketSnapshot::$revokedAt`). Nothing failed. It
 *   would fail the first time a revocation was written.
 *
 *   Finding one by luck is not a strategy. This makes it systematic.
 *
 * HOW IT MAPS
 *   A schema named `Ticket` is compared against the table `tickets`:
 *   PascalCase singular → snake_case plural. Schemas with no matching table
 *   (requests, responses, path params, error envelopes) are not entity schemas
 *   and are skipped, so the tool cannot invent findings by mis-mapping them.
 *
 * WHAT IS NOT A DEFECT
 *   - `id` is the public identifier. The column is `public_id`; internal ids are
 *     never exposed (DATABASE.md §9).
 *   - Properties typed `object` or `array` are structures, not columns. `Ticket`
 *     carries a nested `seat` object while the column is `seat_id`.
 *   - Columns missing FROM the contract are fine. `qr_token_hash` and
 *     `created_at` are internal by design; the contract is a subset, not a mirror.
 *
 * THE BASELINE
 *   Known drift is listed in ACCEPTED_DRIFT with the reason it is tolerated.
 *   It is not a silencing mechanism — it is a ratchet. Anything not listed fails
 *   the build, and fixing a gap means deleting its entry here. An accepted item
 *   with no reason is not acceptable, so the list carries them inline.
 */

$openApi = $argv[1] ?? dirname(__DIR__) . '/docs/openapi.yaml';
$ddl = dirname(__DIR__) . '/nabilet_core_spec/migrations.sql';

/**
 * Drift that is known and understood. Remove an entry when the gap is closed.
 * Every entry must say why it is tolerated.
 *
 * @var array<string, array<string, string>> schema => [property => reason]
 */
const ACCEPTED_DRIFT = [
    // Ticket.revoked_at and revoked_reason used to be listed here with the reason
    // "tickets has no revoked_at column". That was FALSE: both columns exist,
    // appended by ALTER TABLE in migration 010. The parser was reading only CREATE
    // blocks, and the wrong answer was recorded as a known gap instead of being
    // investigated. Removed once the parser learned to read ALTER TABLE.
    //
    // Derived, not missing: the hold lives on seat_holds.expires_at and the API
    // composes it onto the line. Not something the table should store.
    'CartItem' => [
        'hold_expires_at' => 'derived, not stored: the hold is a seat_holds row and its '
            . 'expiry is seat_holds.expires_at. Duplicating it onto cart_items would give '
            . 'two sources of truth for one moment.',
    ],
    // Stored under a different name. The suffix is the interesting part: these
    // are the titles AS THEY WERE when the order was placed, not as they are
    // now. The contract drops that distinction.
    'OrderItem' => [
        'event_title' => 'stored as event_title_snapshot. The contract drops the suffix '
            . 'that says the value is frozen at purchase time.',
        'session_title' => 'stored as session_title_snapshot; same suffix concern as event_title.',
        'venue_title' => 'stored as venue_title_snapshot; same suffix concern as event_title.',
    ],
    // Flattened winner view: the API never returns the raw raffle_winners row.
    // place/prize_title come from the linked raffle_prizes row, order_number/
    // customer_email from the winning entry's order, and `notified` is the
    // raffle_winners.notified_at flag surfaced without the _at suffix.
    'RaffleWinner' => [
        'place' => 'derived from raffle_prizes.place via raffle_prize_id; the winner row stores only the foreign key.',
        'prize_title' => 'derived from raffle_prizes.title (the prize the winner took).',
        'order_number' => 'derived from orders.order_number via the winning entry’s order.',
        'customer_email' => 'derived from orders.customer_email via the winning entry’s order (organiser-only).',
        'notified' => 'stored as raffle_winners.notified_at; the contract drops the _at suffix for the boolean flag.',
    ],
];

/** Schemas whose name does not pluralise mechanically onto their table. */
const TABLE_OVERRIDES = [
    'HallSchemaVersion' => 'hall_schema_versions',
    'HallSchema' => 'hall_schema_versions',
];

/**
 * @return array<string, array<string, string>> schema => [property => inline type text]
 */
function parseOpenApiSchemas(string $file): array
{
    $lines = file($file, \FILE_IGNORE_NEW_LINES);
    $schemas = [];
    $current = null;
    $property = null;
    $inherits = [];

    foreach ($lines as $line) {
        // A schema header is indented exactly four spaces and ends in a colon.
        if (preg_match('/^    ([A-Z][A-Za-z0-9]*):\s*$/', $line, $m)) {
            $current = $m[1];
            $schemas[$current] = [];
            $property = null;

            continue;
        }

        // Any other four-space key ends the current schema's own properties.
        if ($current !== null && preg_match('/^    \S/', $line)) {
            $current = null;
            $property = null;

            continue;
        }

        if ($current === null) {
            continue;
        }

        // `allOf: [ {$ref: X}, {type: object, properties: ...} ]` — схема
        // СОБИРАЕТСЯ из другой, а не объявляет поля сама.
        //
        // ЗАЧЕМ ЭТОТ ВЕТВЕНИЕ. `EventFollow` перестал выписывать поля второй
        // раз и стал `allOf` от `EventFavorite`: раньше совпадение формы двух
        // ресурсов держалось на предложении в докблоке, то есть правило жило в
        // прозе. Но линейный парсер выше видит свойства только на отступе 8, а
        // под `allOf` они уходят на 12 — и гейт начинал бы сравнивать
        // `event_follows` с ПУСТЫМ набором полей. Это не «меньше замечаний», а
        // ослепшая проверка: расхождение по любой колонке стало бы невидимым.
        // Поэтому `$ref` разрешается, и поля родителя подмешиваются сюда же.
        if (preg_match('/^      allOf:\s*$/', $line)) {
            $inherits[$current] = [];
            $property = null;

            continue;
        }

        // Кавычка после имени необязательна: `$ref: '#/.../EventFavorite'` и
        // `$ref: "#/.../EventFavorite"` встречаются в одном документе.
        if (isset($inherits[$current]) && preg_match('/^\s+- \$ref: .*\/schemas\/([A-Za-z0-9]+)[\'"]?\s*$/', $line, $m)) {
            $inherits[$current][] = $m[1];

            continue;
        }

        // A property is indented eight spaces: `        revoked_at: { type: string }`
        // A property is indented eight spaces: `        revoked_at: { type: string }`.
        // Its TYPE may be on the following lines though (`seo:` / `type: object`),
        // so the whole block is kept — deciding "is this a structure or a column"
        // from the header line alone reports every nested object as drift.
        if (preg_match('/^        ([a-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
            $property = $m[1];
            $schemas[$current][$property] = $m[2];

            continue;
        }

        if ($property !== null && preg_match('/^          /', $line)) {
            $schemas[$current][$property] .= "\n" . $line;
        }
    }

    // Наследованное: поля родителя становятся полями наследника, если он их не
    // переобъявил. Порядок — от родителя к наследнику, чтобы собственное
    // объявление побеждало (ровно так же, как это делает OpenAPI).
    foreach ($inherits as $schema => $parents) {
        foreach ($parents as $parent) {
            foreach ($schemas[$parent] ?? [] as $name => $declaration) {
                $schemas[$schema][$name] ??= $declaration;
            }
        }
    }

    return $schemas;
}

/**
 * @return array<string, list<string>> table => columns
 */
function parseDdlColumns(string $file): array
{
    $sql = file_get_contents($file);
    $tables = [];

    preg_match_all(
        '/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\n\) ENGINE=/s',
        $sql,
        $matches,
        \PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $columns = [];

        foreach (explode("\n", $match[2]) as $line) {
            $line = trim($line);

            // Table-level clauses, not columns.
            //
            // The lookahead is load-bearing. Without it, a case-insensitive
            // `CHECK` matches the first five letters of the column
            // `checkin_device_id` and the column is silently dropped — which is
            // how this tool first reported offline_bundles as missing a column
            // it plainly has.
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|INDEX|CONSTRAINT|CHECK|FULLTEXT|SPATIAL)(?=[\s(])/i', $line)) {
                continue;
            }

            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            if (preg_match('/^`?(\w+)`?\s/', $line, $m)) {
                $columns[] = $m[1];
            }
        }

        $tables[$match[1]] = $columns;
    }

    // Columns added by ALTER TABLE.
    //
    // Migration 010 closes the ТЗ gaps by appending columns to tables that already
    // exist, so `tickets.revoked_at`, `tickets.revoked_reason` and
    // `orders.promo_code_id` appear in NO CREATE block. Reading only CREATE blocks
    // made this tool report all three as missing — and the false conclusion was
    // then frozen into ACCEPTED_DRIFT as a "known gap", which is how a parser bug
    // turns into policy. `verify-migrations.php` learned this lesson first; the
    // fix was never carried across to here.
    preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);\s*(?:\n|$)/si', $sql, $alters, \PREG_SET_ORDER);

    foreach ($alters as $alter) {
        $table = $alter[1];

        if (! isset($tables[$table])) {
            continue;
        }

        if (preg_match_all('/ADD\s+COLUMN\s+`?(\w+)`?/i', $alter[2], $added)) {
            foreach ($added[1] as $name) {
                $tables[$table][] = $name;
            }
        }
    }

    return $tables;
}

/**
 * Словари статусов из DDL: `table.column` => значения.
 *
 * Читает ТОЛЬКО `CHECK (status IN (...))` / `CHECK (payment_status IN (...))`
 * из ALTER TABLE. Колонки без ограничения сюда не попадают вовсе — и это
 * осознанно: §3.11 спеки перечисляет их как намеренно свободные, и требовать
 * от контракта перечисления там значило бы выдумывать соглашение.
 *
 * @return array<string, list<string>>
 */
function parseDdlStatusVocabularies(string $file): array
{
    $sql = file_get_contents($file);
    $vocabularies = [];

    preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);\s*(?:\n|$)/si', $sql, $alters, \PREG_SET_ORDER);

    foreach ($alters as $alter) {
        $table = $alter[1];

        if (preg_match('/CHECK\s*\(\s*(\w+)\s+IN\s*\((.*?)\)\s*\)/si', $alter[2], $m)) {
            preg_match_all("/'([^']+)'/", $m[2], $vals);
            $vocabularies[$table . '.' . $m[1]] = $vals[1];
        }
    }

    return $vocabularies;
}

/**
 * Откуда берутся ожидаемые числа: `tools/spec-counts.php`.
 *
 * Зачем проверка вообще нужна. Парсер DDL, который молча недосчитывает, — тот
 * самый отказ, ради которого этот файл и существует, и он уже случался: три
 * колонки были невидимы, и подозрение пало на схему, а не на парсер. Поэтому
 * числа ожидания проверяются ДО того, как сообщается о дрейфе: сломанный
 * парсер падает громко, вместо того чтобы тихо изобрести дрейф.
 *
 * Зачем вынесено в отдельный файл. Числа стояли здесь, в
 * `verify-models-schema.php` и в `verify-live.php` — в трёх местах — и
 * разъезжались трижды: обновляли два файла из трёх. Последний раз коммит
 * `c581afbd` поднял счётчики до 88/911 в двух инструментах, а третий с тех пор
 * падал на первой проверке с обвинением парсера. Теперь число одно на всех, и
 * `spec_counts_problem()` сверяет его с независимым подсчётом `CREATE TABLE` в
 * бандле.
 */
require __DIR__ . '/spec-counts.php';

/**
 * @param array<string, list<string>> $tables
 */
function assertParserSawTheSchema(array $tables): void
{
    $columns = array_sum(array_map('count', $tables));

    if (count($tables) !== EXPECTED_TABLES || $columns !== EXPECTED_COLUMNS) {
        fwrite(\STDERR, sprintf(
            "\n  FAIL  the DDL parser read %d tables / %d columns, expected %d / %d.\n"
            . "        The parser is wrong, not the schema — fix parseDdlColumns() before\n"
            . "        trusting any drift reported below. (Run verify-migrations.php, which\n"
            . "        cross-checks the same file a different way.)\n\n",
            count($tables),
            $columns,
            EXPECTED_TABLES,
            EXPECTED_COLUMNS
        ));

        exit(1);
    }
}

function snakePlural(string $name): string
{
    $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

    return match (true) {
        str_ends_with($snake, 's'), str_ends_with($snake, 'x'),
        str_ends_with($snake, 'ch'), str_ends_with($snake, 'sh') => $snake . 'es',
        preg_match('/[^aeiou]y$/', $snake) === 1 => substr($snake, 0, -1) . 'ies',
        default => $snake . 's',
    };
}

/** Structures, not columns: `Ticket.seat` is an object while the column is seat_id. */
function isStructural(string $declaration): bool
{
    return str_contains($declaration, 'type: object')
        || str_contains($declaration, 'type: array')
        || str_starts_with(ltrim($declaration), '{ $ref')
        || str_contains($declaration, '$ref:');
}

/**
 * Значения `enum` из объявления свойства, или null, если перечисления нет.
 *
 * Объявление бывает в двух формах, и обе встречаются в контракте:
 *   `status: { type: string, enum: [active, converted, abandoned] }`
 *   `status:` с `type: string` и `enum: [...]` на следующих строках.
 * Поэтому ищется по всему блоку, а не в первой строке.
 *
 * @return list<string>|null
 */
function enumOf(string $declaration): ?array
{
    if (preg_match('/enum:\s*\[([^\]]*)\]/s', $declaration, $m) !== 1) {
        return null;
    }

    $values = array_map('trim', explode(',', $m[1]));

    return $values === [''] ? [] : $values;
}

// ── run ──────────────────────────────────────────────────────────────────────

echo "\nNABILET Core — contract ↔ schema drift\n";
echo str_repeat('─', 74), "\n\n";

$schemas = parseOpenApiSchemas($openApi);
$tables = parseDdlColumns($ddl);

// Before any drift is reported: prove the parser actually read the schema.
assertParserSawTheSchema($tables);

printf("  Contract: %s\n", $openApi);
printf("  Schema:   %s\n\n", $ddl);
printf("  %d schemas, %d tables, %d columns\n\n", count($schemas), count($tables), array_sum(array_map('count', $tables)));

$compared = 0;
$drift = [];
$accepted = [];
$exposedInternalIds = [];
$statusVocabularies = parseDdlStatusVocabularies($ddl);
$statusDrift = [];

foreach ($schemas as $schema => $properties) {
    $table = TABLE_OVERRIDES[$schema] ?? snakePlural($schema);

    if (! isset($tables[$table])) {
        continue; // not an entity schema: request, response, path param, envelope
    }

    $compared++;
    $columns = $tables[$table];

    foreach ($properties as $property => $declaration) {
        if (isStructural($declaration)) {
            continue;
        }

        // `id` on the wire is the public identifier, so the column is public_id
        // where the table has one. Where it does not — pivot and child tables
        // like user_roles or cart_items — the contract is naming the internal
        // id, which is storable but contradicts DATABASE.md §9. That is reported
        // separately rather than dressed up as missing-column drift.
        $column = $property === 'id' && in_array('public_id', $columns, true) ? 'public_id' : $property;

        if (in_array($column, $columns, true)) {
            if ($property === 'id' && $column === 'id') {
                $exposedInternalIds[] = ['schema' => $schema, 'table' => $table];
            }

            // Контракт перечисляет словарь — сверь его с тем, что допускает база.
            //
            // ЗАЧЕМ. `status: { type: string }` — обещание «любая строка». Пока
            // CHECK нет, оно правда; как только ограничение появляется, контракт
            // начинает обещать меньше, чем позволяет схема, и клиент не знает,
            // какие значения вообще возможны. Так и вышло: десять колонок имели
            // CHECK, а контракт остался «любая строка» — и ни один гейт этого не
            // видел, потому что ни verify-openapi, ни verify-contract-schema, ни
            // verify-spec-parity не смотрели на enum'ы.
            //
            // Сравнение идёт с CHECK, а не с владельцем в PHP: контракт описывает
            // границу хранения, и она в схеме.
            $enum = enumOf($declaration);

            if ($enum !== null && isset($statusVocabularies[$table . '.' . $column])) {
                $allowed = $statusVocabularies[$table . '.' . $column];

                if ($enum !== $allowed) {
                    $statusDrift[] = [
                        'schema' => $schema,
                        'table' => $table,
                        'property' => $property,
                        'contract' => $enum,
                        'allowed' => $allowed,
                    ];
                }
            }

            continue;
        }

        $reason = ACCEPTED_DRIFT[$schema][$property] ?? null;

        if ($reason !== null) {
            $accepted[] = ['schema' => $schema, 'table' => $table, 'property' => $property, 'reason' => $reason];

            continue;
        }

        $drift[] = ['schema' => $schema, 'table' => $table, 'property' => $property];
    }
}

printf("  Entity schemas compared: %d\n\n", $compared);

if ($drift !== []) {
    echo "  DRIFT — the contract promises a field the table cannot store:\n\n";

    foreach ($drift as $d) {
        printf("    FAIL  %s.%s is not a column of %s\n", $d['schema'], $d['property'], $d['table']);
    }

    echo "\n";
}

if ($accepted !== []) {
    echo "  ACCEPTED — known and understood, each with the reason it stands:\n\n";

    foreach ($accepted as $a) {
        printf("    %s.%s is not a column of %s\n", $a['schema'], $a['property'], $a['table']);
        printf("      %s\n", $a['reason']);
    }

    echo "\n";
}

if ($exposedInternalIds !== []) {
    echo "  NOTE — contract exposes `id` on a table with no public_id:\n\n";

    foreach ($exposedInternalIds as $n) {
        printf("    %s -> %s (DATABASE.md §9: internal ids never leave the API)\n", $n['schema'], $n['table']);
    }

    echo "\n";
}

if ($statusDrift !== []) {
    echo "  STATUS DRIFT — the contract enumerates a vocabulary the database disagrees with:\n\n";

    foreach ($statusDrift as $d) {
        printf("    FAIL  %s.%s enum does not match %s\n", $d['schema'], $d['property'], $d['table']);
        printf("          contract: %s\n", implode(', ', $d['contract']));
        printf("          schema:   %s\n", implode(', ', $d['allowed']));
    }

    echo "\n";
}

if ($drift === [] && $accepted === [] && $statusDrift === []) {
    echo "  Clean: every field the contract promises exists as a column,\n";
    echo "         and every enumerated status matches its CHECK constraint.\n\n";
}

echo str_repeat('─', 74), "\n";
printf(
    "  %d entity schemas, %d drift, %d accepted, %d status drift\n\n",
    $compared,
    count($drift),
    count($accepted),
    count($statusDrift)
);

exit($drift === [] && $statusDrift === [] ? 0 : 1);
