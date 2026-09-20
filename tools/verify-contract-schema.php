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
    // The gap that motivated this tool. The contract promises two fields the
    // tickets table simply does not have, and the domain already reads one of
    // them (TicketSnapshot::$revokedAt). It would fail on the first revocation.
    'Ticket' => [
        'revoked_at' => 'tickets has no revoked_at column; REVIEW-spec-bundle.md §3.11. '
            . 'Needs a column in the spec bundle, which is the source of truth.',
        'revoked_reason' => 'same gap as revoked_at: the reason a ticket was revoked is '
            . 'not stored anywhere.',
    ],
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

    return $tables;
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

// ── run ──────────────────────────────────────────────────────────────────────

echo "\nNABILET Core — contract ↔ schema drift\n";
echo str_repeat('─', 74), "\n\n";

$schemas = parseOpenApiSchemas($openApi);
$tables = parseDdlColumns($ddl);

printf("  Contract: %s\n", $openApi);
printf("  Schema:   %s\n\n", $ddl);
printf("  %d schemas, %d tables\n\n", count($schemas), count($tables));

$compared = 0;
$drift = [];
$accepted = [];
$exposedInternalIds = [];

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

if ($drift === [] && $accepted === []) {
    echo "  Clean: every field the contract promises exists as a column.\n\n";
}

echo str_repeat('─', 74), "\n";
printf("  %d entity schemas, %d drift, %d accepted\n\n", $compared, count($drift), count($accepted));

exit($drift === [] ? 0 : 1);
