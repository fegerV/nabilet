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
];

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
 * Tokens that mean "this literal is a status value".
 *
 * A hit needs a `status` token in the window BEFORE the literal — either the
 * property/column name (`->status`, `where('status', …)`) or the qualified
 * column (`'inventory_items.status'`).
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @param  int  $i  index of the candidate literal
 */
function hasStatusInWindow(array $tokens, int $i): bool
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

/** @var array<string, list<string>> */
$vocabularies = [];

foreach ($owners as $column => $class) {
    $vocabularies[$column] = vocabularyOf($class);
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

        foreach ($vocabularies as $column => $values) {
            if (! in_array($value, $values, true)) {
                continue;
            }

            if (! hasStatusInWindow($tokens, $i)) {
                continue;
            }

            $line = $token[2];

            $violations[] = sprintf(
                '%s:%d  %s = \'%s\' is a literal; call the owner instead',
                $relative,
                $line,
                $column,
                $value
            );
        }
    }
}

echo "\nNABILET Core — status-literal verification\n";
echo str_repeat('─', 74), "\n\n";

foreach ($vocabularies as $column => $values) {
    printf("  %-24s %d values from %s\n", $column, count($values), basename(str_replace('\\', '/', $owners[$column])));
}

printf("\n  %d files scanned under app/\n", count($scanned));

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
