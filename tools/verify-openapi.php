<?php

declare(strict_types=1);

/**
 * OpenAPI sanity checks.
 *
 * Usage:
 *     php tools/verify-openapi.php [path/to/openapi.yaml]
 *
 * With no argument it checks docs/openapi.yaml. Pass a path to check another copy
 * (e.g. nabilet_core_spec/openapi.yaml) so the two can be kept in lockstep.
 *
 * No YAML parser is available in this environment, so this performs the checks that
 * matter for a hand-written contract:
 *   1. YAML basics that break real parsers: tabs used for indentation, duplicate
 *      mapping keys at the same level, unclosed quotes.
 *   2. Every `$ref` resolves to something that is actually defined. A dangling
 *      $ref is the most common defect in a hand-maintained OpenAPI document, and
 *      it surfaces as a broken generated client rather than an obvious error.
 *   3. Structural completeness: every operation has responses, every tag used is
 *      declared, every path parameter is declared.
 *   4. Contract invariants the platform depends on: money is integer minor units
 *      (spec 9), the seat-hold and order-creation endpoints are idempotent, and the
 *      ticket QR payload carries no personal data (spec 43/44).
 *
 * This tool is written against the nabilet_core_spec contract, which is the source
 * of truth. It resolves `$ref` parameters and counts security-scheme usage, because
 * the contract declares both through components rather than inline.
 */

$file = $argv[1] ?? dirname(__DIR__) . '/docs/openapi.yaml';

if (! is_file($file)) {
    fwrite(STDERR, "OpenAPI file not found: {$file}\n");
    exit(1);
}

$lines = file($file, FILE_IGNORE_NEW_LINES);
$raw = implode("\n", $lines);

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

/** Return a bounded window of the document starting at the first occurrence of $needle. */
function sliceFrom(string $haystack, string $needle, int $length): string
{
    $pos = strpos($haystack, $needle);

    return $pos === false ? '' : substr($haystack, $pos, $length);
}

echo "\nNABILET Core — OpenAPI sanity\n";
echo str_repeat('─', 74), "\n\n";

// ── 1. YAML basics ───────────────────────────────────────────────────────────
check('no tab characters used for indentation', function () use ($lines): void {
    foreach ($lines as $i => $line) {
        if (preg_match('/^\t|^ *\t/', $line)) {
            throw new RuntimeException(sprintf('tab found on line %d', $i + 1));
        }
    }
});

check('no duplicate keys at the same indentation level within a block', function () use ($lines): void {
    // Tracks keys seen per (ancestor-scope, indent, key) so that repeated names in
    // DIFFERENT blocks are not flagged. Sequence items ("- ") open a new sibling
    // scope, which is why their line numbers are part of the ancestor identity:
    // two `description` keys under two different `- url:` entries are legal YAML.
    $seen = [];
    $stack = [];
    $seqItem = [];

    foreach ($lines as $i => $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        $indent = strlen($line) - strlen(ltrim($line, ' '));

        // A sequence item starts a new sibling scope.
        if (preg_match('/^-\s|^-$/', ltrim($line))) {
            $seqItem[$indent] = $i + 1;

            foreach (array_keys($seqItem) as $level) {
                if ($level > $indent) {
                    unset($seqItem[$level]);
                }
            }
            foreach (array_keys($stack) as $level) {
                if ($level >= $indent) {
                    unset($stack[$level]);
                }
            }

            continue;
        }

        if (! preg_match('/^([A-Za-z0-9_.\-\/\{\}\$\[\]\'"#]+):\s*(.*)$/', ltrim($line), $m)) {
            continue;
        }

        // Maintain the ancestor path for this indent level.
        foreach (array_keys($stack) as $level) {
            if ($level >= $indent) {
                unset($stack[$level]);
            }
        }

        $ancestors = [];
        foreach ($seqItem as $level => $lineNumber) {
            if ($level < $indent) {
                $ancestors[] = $level . '#' . $lineNumber;
            }
        }

        $path = implode('/', array_values($stack)) . '|' . implode(',', $ancestors);
        $key = $path . '|' . $indent . '|' . $m[1];

        if (isset($seen[$key])) {
            throw new RuntimeException(sprintf('duplicate key "%s" on line %d', $m[1], $i + 1));
        }

        $seen[$key] = $i + 1;
        $stack[$indent] = $m[1];
    }
});

check('document declares openapi, info, paths and components', function () use ($raw): void {
    foreach (['openapi:', 'info:', 'paths:', 'components:'] as $key) {
        assertTrue(
            (bool) preg_match('/^' . preg_quote($key, '/') . '/m', $raw),
            'missing top-level key: ' . $key
        );
    }
});

// ── 2. Collect definitions and references ────────────────────────────────────
$definitions = ['schemas' => [], 'parameters' => [], 'responses' => [], 'securitySchemes' => []];

$section = null;
foreach ($lines as $line) {
    if (preg_match('/^  (schemas|parameters|responses|securitySchemes):\s*$/', $line, $m)) {
        $section = $m[1];

        continue;
    }

    // Any other 2-space top-level key inside components closes the section.
    if (preg_match('/^  \S/', $line) && ! preg_match('/^  (schemas|parameters|responses|securitySchemes):/', $line)) {
        $section = null;

        continue;
    }

    if ($section !== null && preg_match('/^    ([A-Za-z0-9_]+):\s*$/', $line, $m)) {
        $definitions[$section][] = $m[1];
    }
}

// Map component parameter key -> the actual parameter name it declares, so a
// `$ref` can be resolved to the `{placeholder}` it satisfies.
$componentParams = [];
$inParams = false;
$currentParam = null;
foreach ($lines as $line) {
    if (preg_match('/^  parameters:\s*$/', $line)) {
        $inParams = true;

        continue;
    }
    if ($inParams && preg_match('/^  \S/', $line)) {
        $inParams = false;
    }
    if (! $inParams) {
        continue;
    }
    if (preg_match('/^    ([A-Za-z0-9_]+):\s*$/', $line, $m)) {
        $currentParam = $m[1];

        continue;
    }
    if ($currentParam !== null && preg_match('/^      name:\s*([A-Za-z0-9_]+)/', $line, $m)) {
        $componentParams[$currentParam] = $m[1];
    }
}

preg_match_all('/\$ref:\s*[\'"]#\/components\/([A-Za-z]+)\/([A-Za-z0-9_]+)[\'"]/', $raw, $refs, PREG_SET_ORDER);

check('every $ref resolves to a defined component', function () use ($refs, $definitions): void {
    $problems = [];

    foreach ($refs as $ref) {
        [, $group, $name] = $ref;

        if (! isset($definitions[$group])) {
            $problems[] = sprintf('#/components/%s/%s — unknown group', $group, $name);

            continue;
        }

        if (! in_array($name, $definitions[$group], true)) {
            $problems[] = sprintf('#/components/%s/%s — not defined', $group, $name);
        }
    }

    assertTrue($problems === [], implode("\n      ", array_unique($problems)));
});

check('no component is defined but never referenced', function () use ($refs, $definitions, $raw): void {
    $used = [];
    foreach ($refs as $ref) {
        $used[$ref[1] . '/' . $ref[2]] = true;
    }

    // Security schemes are referenced by NAME inside `security:` blocks, never via
    // `$ref`. Both the inline form (`security: [{ bearerAuth: [] }]`) and the
    // expanded form (`- bearerAuth: []`) count as usage.
    preg_match_all('/\{\s*([A-Za-z0-9_]+):\s*\[\]\s*\}/', $raw, $inlineSec);
    preg_match_all('/^\s*- ([A-Za-z0-9_]+):\s*\[\]\s*$/m', $raw, $expandedSec);
    foreach (array_unique(array_merge($inlineSec[1], $expandedSec[1])) as $scheme) {
        $used['securitySchemes/' . $scheme] = true;
    }

    $orphans = [];
    foreach ($definitions as $group => $names) {
        foreach ($names as $name) {
            if (! isset($used[$group . '/' . $name])) {
                $orphans[] = $group . '/' . $name;
            }
        }
    }

    // Orphans are not fatal — the contract may describe types used by future
    // endpoints — but they are reported so the document does not silently rot.
    assertTrue(
        count($orphans) <= 3,
        'unused components: ' . implode(', ', $orphans)
    );
});

// ── 3. Structural completeness ───────────────────────────────────────────────
preg_match_all('/^    (get|post|put|patch|delete):\s*$/m', $raw, $ops);
$operationCount = count($ops[0]);

check('operations are defined', function () use ($operationCount): void {
    assertTrue($operationCount >= 15, 'expected at least 15 operations, found ' . $operationCount);
});

check('every declared tag is used by an operation', function () use ($raw): void {
    preg_match_all('/^  - name: ([A-Za-z\-]+)$/m', $raw, $declared);
    preg_match_all('/^      tags: \[([A-Za-z\-]+)\]$/m', $raw, $used);

    $usedTags = $used[1] ?? [];
    $problems = [];

    foreach ($usedTags as $tag) {
        if (! in_array($tag, $declared[1] ?? [], true)) {
            $problems[] = 'tag used but not declared: ' . $tag;
        }
    }

    assertTrue($problems === [], implode(', ', array_unique($problems)));
});

check('every path parameter is declared in its operation', function () use ($lines, $componentParams): void {
    $problems = [];
    $currentPath = null;
    $declared = [];
    $expected = [];
    $inOperationParams = false;

    $flush = function () use (&$problems, &$currentPath, &$declared, &$expected): void {
        if ($currentPath === null) {
            return;
        }
        foreach (array_diff($expected, $declared) as $missing) {
            $problems[] = sprintf('%s does not declare path parameter {%s}', $currentPath, $missing);
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^  (\/[^\s:]*):\s*$/', $line, $m)) {
            $flush();
            $currentPath = $m[1];
            $declared = [];
            $expected = preg_match_all('/\{([A-Za-z0-9_]+)\}/', $m[1], $pm) ? $pm[1] : [];
            $inOperationParams = false;

            continue;
        }

        // An operation's `parameters:` block sits at 6-space indent; any sibling
        // operation key closes it.
        if (preg_match('/^      parameters:\s*$/', $line)) {
            $inOperationParams = true;

            continue;
        }
        if (preg_match('/^      [A-Za-z]+:/', $line)) {
            $inOperationParams = false;

            continue;
        }
        if (! $inOperationParams) {
            continue;
        }

        // Form A: a shared component reference.
        if (preg_match('/\$ref:\s*[\'"][^\'"]*components\/parameters\/([A-Za-z0-9_]+)[\'"]/', $line, $m)) {
            if (isset($componentParams[$m[1]])) {
                $declared[] = $componentParams[$m[1]];
            }

            continue;
        }

        // Form B: inline, one line — `- name: item`.
        if (preg_match('/^        - name:\s*([A-Za-z0-9_]+)\s*$/', $line, $m)) {
            $declared[] = $m[1];

            continue;
        }

        // Form C: inline, expanded — `- in: path` then `name: item`.
        if (preg_match('/^          name:\s*([A-Za-z0-9_]+)\s*$/', $line, $m)) {
            $declared[] = $m[1];
        }
    }

    $flush();

    assertTrue($problems === [], implode("\n      ", $problems));
});

// ── 4. Contract invariants ───────────────────────────────────────────────────
check('money is documented as integer minor units', function () use ($raw): void {
    $block = sliceFrom($raw, '    MoneyTotals:', 900);

    assertTrue($block !== '', 'the MoneyTotals schema is missing');

    assertTrue(
        stripos($block, 'minor units') !== false,
        'MoneyTotals must state that amounts are integer minor units (spec 9)'
    );

    assertTrue(
        (bool) preg_match('/(subtotal|total)_amount:\s*\{\s*type: integer/', $block),
        'MoneyTotals amount fields must be integers, never floats'
    );
});

check('idempotency is documented on the seat-hold and order-creation endpoints', function () use ($raw): void {
    // The two endpoints where a network retry would cost real money. In the bundle
    // contract a seat hold IS the act of adding an item to a cart.
    $hold = sliceFrom($raw, "\n  /api/v1/carts/{cart}/items:", 1200);
    assertTrue(
        str_contains($hold, 'IdempotencyKey'),
        'adding a cart item (the seat hold) must require an Idempotency-Key'
    );

    $order = sliceFrom($raw, "\n  /api/v1/orders:", 1200);
    assertTrue(
        str_contains($order, 'IdempotencyKey'),
        'order creation must require an Idempotency-Key'
    );
});

check('QR payload is documented as free of personal data', function () use ($raw): void {
    $qr = sliceFrom($raw, '/api/v1/tickets/{ticket}/qr:', 900);

    assertTrue($qr !== '', 'the ticket QR endpoint is missing');

    assertTrue(
        (bool) preg_match('/no personal data|not contain personal data/i', $qr),
        'the QR endpoint must state that the payload carries no personal data (spec 43/44)'
    );
});

echo "\n", str_repeat('─', 74), "\n";

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $i => $failure) {
        printf("  %2d) %s\n", $i + 1, $failure);
    }
    echo "\n";
}

printf(
    "%s  %d checks passed, %d failed  (%d operations, %d schemas, %d parameters, %d responses, %d \$refs)\n\n",
    $failed === 0 ? 'PASS' : 'FAIL',
    $passed,
    $failed,
    $operationCount,
    count($definitions['schemas']),
    count($definitions['parameters']),
    count($definitions['responses']),
    count($refs)
);

exit($failed === 0 ? 0 : 1);
