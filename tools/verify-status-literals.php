<?php

declare(strict_types=1);

/**
 * Status-literal guard — "the rule was copied, not called", enforced.
 *
 * Usage:
 *     php tools/verify-status-literals.php
 *
 * WHY THIS EXISTS
 *   Seven times in one audit the same defect was found and fixed by hand: a
 *   status vocabulary had ONE owner (a state machine, a Domain value object),
 *   and N call sites carried their own copy of the rule as string literals.
 *   Every one of those copies was correct on the day it was written; every one
 *   of them drifted afterwards, because editing one copy does not remind
 *   anyone about the others.
 *
 *   The copies were not cosmetic. Two of them were live defects:
 *     - `SessionController` validated `status` against
 *       `scheduled,on_sale,held,completed,cancelled` — a list wrong in both
 *       directions. `held` is an INVENTORY-ITEM status, so a session write
 *       with it passed Laravel and died on MySQL: 500 instead of 422. The
 *       states `draft`, `sold_out` and `closed` could not be set at all.
 *     - `carts.status` was written as a bare string in 21 places. The class
 *       that presented itself as the vocabulary's "single home" lived in the
 *       archived `Superseded/` cluster, declared a fourth value (`expired`)
 *       that `ck_carts_status` refuses, and claimed in its docblock that the
 *       column has no CHECK at all — it does.
 *
 *   `tools/verify-state-machines.php` already proves each MACHINE agrees with
 *   its CHECK constraint. Nothing proved the CODE agrees with the machine.
 *   That is the gap this gate closes.
 *
 * WHAT IT CHECKS
 *   For every vocabulary below, no string literal equal to one of its values
 *   may appear in live code (`app/`) outside the owning class, in a position
 *   where it is being compared with or assigned to a status. The owner is
 *   found by reflection — the vocabulary is the class's own public constants,
 *   so adding a state to the machine cannot silently pass here either.
 *
 * WHY A TOKEN STREAM AND NOT A GREP
 *   Docblocks in this codebase legitimately discuss the literals ("`held` is
 *   an inventory-item status"), and response payloads carry keys that happen
 *   to be spelled like a status. Comments and string CONTENT are dropped
 *   before scanning, so only real code is judged — the same approach as
 *   `tools/verify-purity.php`.
 *
 * WHAT IS DELIBERATELY NOT FLAGGED
 *   The allowlist below. Each entry is a place where the same word belongs to
 *   a DIFFERENT vocabulary, with a note saying which. An allowlist entry
 *   without a reason is a bug in this file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// The vocabulary is read out of the owner class by reflection, so the class has
// to load. `tests/autoload.php` is the project's dependency-free autoloader —
// no Composer, no framework, no database — which keeps this gate runnable in a
// sandbox the way `verify-purity.php` is.
require __DIR__ . '/../tests/autoload.php';

$root = dirname(__DIR__);

/**
 * Vocabularies that have one owner, and that owner's file.
 *
 * Adding a vocabulary here is the whole cost of extending the guard: the
 * values are read from the class itself, so there is no second list to keep
 * in step.
 *
 * @var array<string, class-string>
 */
$owners = [
    'inventory_items.status' => Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine::class,
    'carts.status' => Nabilet\Modules\Cart\Domain\CartStatus::class,
    'sessions.status' => Nabilet\Modules\Sessions\StateMachines\SessionStateMachine::class,
];

/**
 * WHICH VALUES ARE ACTUALLY ENFORCED — the ones the schema makes unambiguous.
 *
 * A value like `active` is the status of users, organizations, venues, halls,
 * seats, carts, check-in devices and raffles. Flagging every `'active'` next to
 * a `status` token would report `organizations.status` as a copy of
 * `carts.status` — noise, and noise gets a gate switched off. So the spec's own
 * CHECK constraints decide: a value is enforced only when exactly ONE column
 * in the whole schema allows it.
 *
 * That still covers the expensive drifts, because those are the values that
 * identify a vocabulary on their own: `sold_out`, `held`, `available`, `sold`
 * (inventory), `converted`, `abandoned` (carts), `on_sale` (sessions).
 *
 * @var array<string, list<string>>  column => enforced values
 */
$specSql = (string) file_get_contents($root . '/nabilet_core_spec/migrations.sql');

/** @var array<string, array<string, true>> value => columns that allow it */
$valueToColumns = [];

preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);\s*(?:\n|$)/si', $specSql, $alters, PREG_SET_ORDER);

foreach ($alters as $alter) {
    if (! preg_match('/CHECK\s*\(\s*status\s+IN\s*\((.*?)\)\s*\)/si', $alter[2], $m)) {
        continue;
    }

    preg_match_all("/'([^']+)'/", $m[1], $vals);

    foreach ($vals[1] as $value) {
        $valueToColumns[$value][$alter[1]] = true;
    }
}

$enforced = [];

foreach ($owners as $column => $class) {
    foreach (vocabularyOf($class) as $value) {
        $columns = array_keys($valueToColumns[$value] ?? []);
        $table = explode('.', $column)[0];

        if (count($columns) === 1 && $columns[0] === $table) {
            $enforced[$column][] = $value;
        }
    }
}

/**
 * Known-good spellings that belong to another vocabulary.
 *
 * @var array<string, string>  "file:line-ish key" => why it is allowed
 */
$allowlist = [
    // schema.org's own `availability` vocabulary for JSON-LD offers. It shares
    // the word `sold_out` with the inventory machine by coincidence, and it is
    // written as a schema.org URI, not into a database column.
    'Seo/Services/StructuredDataService.php' => 'JSON-LD schema.org availability vocabulary',
];

/** @var list<string> */
$violations = [];

/**
 * Pull the vocabulary out of the owner class by reflection.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function vocabularyOf(string $class): array
{
    $values = [];

    foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
        if (! $constant->isPublic()) {
            continue;
        }

        $value = $constant->getValue();

        if (is_string($value) && $value !== '') {
            $values[] = $value;
        }
    }

    return $values;
}

/**
 * Is there a `status` token in front of this literal at all?
 *
 * Without this, every array key or bind parameter that happens to be spelled
 * like a status value (`'available' => …`, `selectRaw(…, ['available'])`)
 * would be reported — noise, and noise is how a gate gets switched off.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @param  int  $i  index of the candidate literal
 */
function hasStatusToken(array $tokens, int $i): bool
{
    for ($j = max(0, $i - 8); $j < $i; $j++) {
        $token = $tokens[$j];

        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'status') {
            return true;
        }

        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = trim($token[1], "'\"");

            if ($value === 'status' || str_ends_with($value, '.status')) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Which column the `status` in front of this literal belongs to, when the code
 * says so.
 *
 * Returns the table name when the window contains a QUALIFIED column
 * (`where('inventory_items.status', …)`) — that is the code naming the table
 * out loud, and it makes a shared value like `sold_out` unambiguous. Returns
 * null for a bare `->status` / `where('status', …)`, where only a value unique
 * to one column can be judged safely.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @param  int  $i  index of the candidate literal
 */
function statusColumnInWindow(array $tokens, int $i): ?string
{
    for ($j = max(0, $i - 8); $j < $i; $j++) {
        $token = $tokens[$j];

        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'status') {
            return null;
        }

        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = trim($token[1], "'\"");

            if (str_contains($value, '.')) {
                return explode('.', $value)[0];
            }
        }
    }

    return null;
}

/**
 * Full vocabulary per column, straight from the owner class.
 *
 * @var array<string, list<string>>
 */
$vocabularies = [];

foreach ($owners as $column => $class) {
    $vocabularies[$column] = vocabularyOf($class);
}

/**
 * Values that belong to exactly one column in the whole schema.
 *
 * These are the only ones a BARE `status` can be judged against — with
 * `where('status', 'sold_out')` the code has not said which table it means.
 *
 * @var array<string, list<string>>
 */
$unambiguous = $enforced;

/** Values the schema leaves ambiguous — reported, not enforced. */
$ambiguous = [];

foreach ($owners as $column => $class) {
    foreach (vocabularyOf($class) as $value) {
        if (in_array($value, $vocabularies[$column] ?? [], true)) {
            continue;
        }

        $columns = array_keys($valueToColumns[$value] ?? []);
        $ambiguous[] = sprintf('%s.%s is also the status of %s', $column, $value, implode(', ', $columns) ?: 'no CHECK-constrained column');
    }
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
);

/** @var list<string> */
$scanned = [];

foreach ($files as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root)) + 1), '/');
    $scanned[] = $relative;

    foreach ($owners as $class) {
        // The owner declares its own vocabulary as literals — that is the
        // point of it. Excluded by file, so a renamed constant cannot hide.
        if (str_contains($path, str_replace('\\', '/', (new ReflectionClass($class))->getFileName()))) {
            continue 2;
        }
    }

    $allowed = false;

    foreach (array_keys($allowlist) as $needle) {
        if (str_contains($relative, $needle)) {
            $allowed = true;
            break;
        }
    }

    if ($allowed) {
        continue;
    }

    $tokens = token_get_all(file_get_contents($path));

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $value = trim($token[1], "'\"");
        $qualified = statusColumnInWindow($tokens, $i);

        foreach ($owners as $column => $class) {
            $table = explode('.', $column)[0];

            if ($qualified !== null) {
                // The code named the table (`'inventory_items.status'`), so the
                // whole vocabulary of that column is fair game.
                if ($qualified !== $table || ! in_array($value, $vocabularies[$column], true)) {
                    continue;
                }
            } elseif (! hasStatusToken($tokens, $i)
                || ! in_array($value, $unambiguous[$column] ?? [], true)) {
                // Bare `status`: judge only values this column owns alone, and
                // only where a status is actually being written.
                continue;
            }

            $violations[] = sprintf(
                '%s:%d  %s = \'%s\' is a literal; call the owner instead',
                $relative,
                $token[2],
                $column,
                $value
            );
        }
    }
}

echo "\nNABILET Core — status-literal verification\n";
echo str_repeat('─', 74), "\n\n";

foreach ($owners as $column => $class) {
    printf(
        "  %-24s %d of %d values from %s\n",
        $column,
        count($unambiguous[$column] ?? []),
        count($vocabularies[$column]),
        basename(str_replace('\\', '/', $class))
    );
}

printf("\n  %d files scanned under app/\n", count($scanned));

if ($ambiguous !== []) {
    echo "\n  Not enforced — the value belongs to more than one column:\n";

    foreach ($ambiguous as $note) {
        printf("    ~ %s\n", $note);
    }
}

if ($violations !== []) {
    echo "\n";

    foreach ($violations as $violation) {
        printf("  FAIL  %s\n", $violation);
    }
}

echo "\n", str_repeat('─', 74), "\n";
printf("  %d violations\n", count($violations));

if ($violations !== []) {
    echo "\n  A status written as a literal has no owner to remind the next\n";
    echo "  editor that it changed. Call the class that declares the vocabulary.\n";
}

echo "\n";
exit($violations === [] ? 0 : 1);
