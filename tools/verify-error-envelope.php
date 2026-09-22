<?php

declare(strict_types=1);

/**
 * Ratchet: every JSON error body in `app/` must be the §66 envelope.
 *
 *     {"error":{"code":…,"message":…,"details":…,"request_id":…}}
 *
 * Why a static check and not just tests
 * -------------------------------------
 * The envelope is violated by *omission* — a controller writes
 * `response()->json(['message' => 'User not found'], 404)` and nothing fails. That is
 * exactly how this codebase ended up with the framework's own error shape on every
 * path the framework owns (validation, 404, 401, 403, 429) while `AppError` sat unused
 * in `app/Core/Errors/`. A test suite only covers the paths someone thought to write a
 * test for; this covers all of them, including the ones added next month.
 *
 * Checks
 * ------
 *   E1  `'error' => '<string literal>'`            — `error` must be an object
 *   E2  `'error' => $x->getMessage()`              — ditto; also publishes internals
 *   E3  `'error' => [ … ]` with a key outside       — the contract reserves
 *       {code, message, details, request_id}          those four keys
 *   E4  a 4xx/5xx `response()->json([…], <status>)` whose body has no `error` key
 *   E5  `abort(...)`                               — Laravel renders it in its own
 *                                                    shape, not ours
 *   E6  `exists:<table>,id` / `unique:<table>,id` without a `bail`+`integer` guard
 *       — the column is BIGINT, so a non-numeric value raises SQLSTATE[22P02] and a
 *       would-be 422 becomes a 500
 *
 * Comments and docblocks are stripped with PHP's own lexer (`token_get_all`) before
 * scanning, so this file and the classes that *document* these anti-patterns do not
 * trip the check. Anything not listed in ACCEPTED fails.
 *
 * Usage:
 *   php tools/verify-error-envelope.php             # check the tree
 *   php tools/verify-error-envelope.php --selftest  # mutation-test the checks
 */

$root = dirname(__DIR__);
$appDir = $root . '/app';

/**
 * Accepted exceptions. Keyed `relative/path.php#CHECK`, each with a reason.
 *
 * A ratchet entry is a claim that the violation is *not* one — it must say why. If a
 * file is refactored and the reason stops being true, the entry must be removed.
 */
const ACCEPTED = [
    // The Filament admin panel is an HTML surface. `abort(403)` renders Laravel's HTML
    // error page, which is the correct behaviour for a browser session — the §66
    // envelope applies to `application/json` API responses only.
    'Http/Middleware/CheckFilamentRole.php#E5' =>
        'Filament admin HTML surface, not the JSON API — abort(403) renders the HTML error page.',

    // Blocked on the authentication rebuild: `AuthController` must be rewritten onto
    // `user_sessions` before its error body can be corrected, because the token it
    // issues is not a Sanctum token (see docs/REVIEW-spec-bundle.md §3.24.8). Fixing
    // the string first would mean touching the file twice.
    'Modules/Auth/Http/Controllers/AuthController.php#E1' =>
        'Blocked on the auth rebuild (user_sessions guard) — see REVIEW-spec-bundle.md §3.24.8.',

    // `HoldSweeper` runs as a console/queue job. These `'error' =>` keys are log
    // context for `Log::error()` and the fields of a summary array returned to the
    // console command — neither is ever serialised as an HTTP response body.
    'Modules/Inventory/Services/HoldSweeper.php#E1' =>
        'Console/queue service: log context and a returned summary array, not an HTTP body.',
    'Modules/Inventory/Services/HoldSweeper.php#E2' =>
        'Console/queue service: log context and a returned summary array, not an HTTP body.',
];

/** The only keys the §66 envelope defines inside `error`. */
const ENVELOPE_KEYS = ['code', 'message', 'details', 'request_id'];

// ─────────────────────────────────────────────────────────────────────────────
// Lexer-based comment stripping
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Blank out comments and docblocks while preserving byte offsets and newlines, so
 * reported line numbers stay correct.
 */
function stripComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                // Replace every non-newline byte with a space: offsets and line numbers
                // are preserved exactly, and no comment text can match a pattern.
                $out .= preg_replace('/[^\n]/', ' ', $text);

                continue;
            }

            $out .= $text;

            continue;
        }

        $out .= $token;
    }

    return $out;
}

/** Byte offset → 1-based line number. */
function lineAt(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

/**
 * Index of the delimiter matching the one at `$open`, or -1.
 *
 * Counts both bracket kinds so that `[ … ( … ) … ]` nests correctly.
 */
function matchDelimiter(string $s, int $open): int
{
    $pairs = ['(' => ')', '[' => ']'];
    $openChar = $s[$open];

    if (!isset($pairs[$openChar])) {
        return -1;
    }

    $depth = 0;
    $len = strlen($s);

    for ($i = $open; $i < $len; $i++) {
        $c = $s[$i];

        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return -1;
}

/** Split a call's argument list on top-level commas. */
function splitTopLevel(string $s): array
{
    $parts = [];
    $depth = 0;
    $current = '';
    $len = strlen($s);

    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];

        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        }

        if ($c === ',' && $depth === 0) {
            $parts[] = $current;
            $current = '';

            continue;
        }

        $current .= $c;
    }

    if (trim($current) !== '') {
        $parts[] = $current;
    }

    return $parts;
}

/** Top-level `'key' =>` names inside an array literal's inner text. */
function arrayKeys(string $inner): array
{
    $keys = [];
    $depth = 0;
    $len = strlen($inner);

    for ($i = 0; $i < $len; $i++) {
        $c = $inner[$i];

        if ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        } elseif ($depth === 0 && $c === "'") {
            // Read a quoted key, then require `=>` to follow.
            $end = strpos($inner, "'", $i + 1);

            if ($end === false) {
                break;
            }

            $candidate = substr($inner, $i + 1, $end - $i - 1);
            $after = ltrim(substr($inner, $end + 1, 3));

            if (str_starts_with($after, '=>')) {
                $keys[] = $candidate;
            }

            $i = $end;
        }
    }

    return $keys;
}

// ─────────────────────────────────────────────────────────────────────────────
// Checks — each returns a list of [line, message]
// ─────────────────────────────────────────────────────────────────────────────

function checkE1(string $code): array
{
    $out = [];

    if (preg_match_all("/'error'\s*=>\s*'/", $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$text, $offset]) {
            $out[] = [lineAt($code, $offset), "E1 flat string `'error' => '…'` — `error` must be an object with `code`+`message`"];
        }
    }

    return $out;
}

function checkE2(string $code): array
{
    $out = [];

    if (preg_match_all("/'error'\s*=>\s*\\\$[A-Za-z_][A-Za-z0-9_]*(?:\([^)]*\))?->getMessage\(\)/", $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$text, $offset]) {
            $out[] = [lineAt($code, $offset), "E2 `'error' => …->getMessage()` — publishes an internal message and gives the client no `code`"];
        }
    }

    return $out;
}

function checkE3(string $code): array
{
    $out = [];

    if (!preg_match_all("/'error'\s*=>\s*\[/", $code, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }

    foreach ($m[0] as [$text, $offset]) {
        $bracket = strpos($code, '[', $offset);

        if ($bracket === false) {
            continue;
        }

        $close = matchDelimiter($code, $bracket);

        if ($close === -1) {
            continue;
        }

        $inner = substr($code, $bracket + 1, $close - $bracket - 1);
        $extra = array_diff(array_unique(arrayKeys($inner)), ENVELOPE_KEYS);

        if ($extra !== []) {
            $out[] = [
                lineAt($code, $offset),
                'E3 `error` carries key(s) outside the contract: ' . implode(', ', $extra)
                    . ' (allowed: ' . implode(', ', ENVELOPE_KEYS) . ')',
            ];
        }
    }

    return $out;
}

function checkE4(string $code): array
{
    $out = [];
    $needle = 'response()->json(';
    $search = 0;

    while (($pos = strpos($code, $needle, $search)) !== false) {
        $search = $pos + strlen($needle);
        $open = $pos + strlen($needle) - 1;
        $close = matchDelimiter($code, $open);

        if ($close === -1) {
            continue;
        }

        $args = splitTopLevel(substr($code, $open + 1, $close - $open - 1));

        if (count($args) < 2) {
            continue;
        }

        $status = trim($args[1]);

        if (!preg_match('/^\d{3}$/', $status) || (int) $status < 400) {
            continue;
        }

        // A body is compliant if it either names the `error` key literally or is built
        // by `AppError::toResponse()`, which is the envelope's single source of truth.
        // `RateLimiter` and `CsrfProtection` take the second route on purpose.
        $body = $args[0];

        if (!str_contains($body, "'error'") && !str_contains($body, 'toResponse(')) {
            $out[] = [
                lineAt($code, $pos),
                "E4 JSON response with status {$status} has no top-level `error` key",
            ];
        }
    }

    return $out;
}

function checkE5(string $code): array
{
    $out = [];

    // `abort(` as a call, not a method call (`->abort(`) and not a declaration.
    if (preg_match_all('/(?<!->)(?<!function )\babort\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$text, $offset]) {
            $out[] = [lineAt($code, $offset), 'E5 `abort()` — Laravel renders it in its own shape; throw an AppError instead'];
        }
    }

    return $out;
}

function checkE6(string $code): array
{
    $out = [];

    if (!preg_match_all('/(?:exists|unique):[a-z_]+,\s*id\b/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }

    foreach ($m[0] as [$text, $offset]) {
        // Window: the enclosing rule — from the nearest preceding `[` or `=>` to the
        // nearest following `]`, `,` or `;`. Covers both the array form
        // (`['bail','integer','exists:…']`) and the pipe form (`'bail|integer|exists:…'`).
        $startBracket = strrpos(substr($code, 0, $offset), '[');
        $startArrow = strrpos(substr($code, 0, $offset), '=>');
        $start = max($startBracket === false ? -1 : $startBracket, $startArrow === false ? -1 : $startArrow);
        $start = $start === -1 ? 0 : $start;

        $rest = substr($code, $offset);
        $endCandidates = [];

        foreach ([']', ',', ';'] as $delim) {
            $p = strpos($rest, $delim);

            if ($p !== false) {
                $endCandidates[] = $p;
            }
        }

        $end = $endCandidates === [] ? strlen($code) : $offset + min($endCandidates);
        $window = substr($code, $start, $end - $start);

        $missing = [];

        if (!preg_match('/\binteger\b/', $window)) {
            $missing[] = 'integer';
        }

        if (!preg_match('/\bbail\b/', $window)) {
            $missing[] = 'bail';
        }

        if ($missing !== []) {
            $out[] = [
                lineAt($code, $offset),
                'E6 `exists/unique:…,id` without ' . implode(' + ', $missing)
                    . ' — the column is BIGINT, so a non-numeric value becomes SQLSTATE[22P02] and a 500',
            ];
        }
    }

    return $out;
}

const CHECKS = [
    'E1' => 'checkE1',
    'E2' => 'checkE2',
    'E3' => 'checkE3',
    'E4' => 'checkE4',
    'E5' => 'checkE5',
    'E6' => 'checkE6',
];

// ─────────────────────────────────────────────────────────────────────────────
// Mutation test — prove every check can go red
// ─────────────────────────────────────────────────────────────────────────────

if (in_array('--selftest', $argv, true)) {
    // Each snippet is a violation that the named check MUST report, paired with a
    // near-miss it must NOT report. A check that cannot go red proves nothing.
    $cases = [
        'E1' => [
            'bad' => "<?php return response()->json(['error' => 'nope'], 422);",
            'good' => "<?php return response()->json(['error' => ['code' => 'X', 'message' => 'y']], 422);",
        ],
        'E2' => [
            'bad' => "<?php return response()->json(['error' => \$e->getMessage()], 422);",
            'good' => "<?php return response()->json(['error' => ['code' => 'X', 'message' => \$e->getMessage()]], 422);",
        ],
        'E3' => [
            'bad' => "<?php return response()->json(['error' => ['code' => 'X', 'retry_after' => 3]], 429);",
            'good' => "<?php return response()->json(['error' => ['code' => 'X', 'details' => ['retry_after' => 3]]], 429);",
        ],
        'E4' => [
            'bad' => "<?php return response()->json(['message' => 'User not found'], 404);",
            'good' => "<?php return response()->json(['error' => ['code' => 'USER_NOT_FOUND']], 404);",
        ],
        'E5' => [
            'bad' => "<?php if (!\$h) { abort(404, 'Hall not found'); }",
            'good' => "<?php throw new NotFoundError('Hall', \$id);",
        ],
        'E6' => [
            'bad' => "<?php \$r = \$q->validate(['session_id' => ['required', 'exists:sessions,id']]);",
            'good' => "<?php \$r = \$q->validate(['session_id' => ['bail', 'required', 'integer', 'exists:sessions,id']]);",
        ],
    ];

    $failures = 0;

    foreach ($cases as $check => $pair) {
        $badCode = stripComments($pair['bad']);
        $goodCode = stripComments($pair['good']);

        $badHits = CHECKS[$check]($badCode);
        $goodHits = CHECKS[$check]($goodCode);

        $canGoRed = $badHits !== [];
        $staysGreen = $goodHits === [];

        printf(
            "  %s  %-3s  red-on-violation=%s  green-on-compliant=%s\n",
            $canGoRed && $staysGreen ? 'PASS' : 'FAIL',
            $check,
            $canGoRed ? 'yes' : 'NO',
            $staysGreen ? 'yes' : 'NO'
        );

        if (!$canGoRed || !$staysGreen) {
            $failures++;
        }
    }

    // The comment stripper is load-bearing: this file and several classes document the
    // anti-patterns inside docblocks. If stripping regressed, the tree check would fail
    // on its own documentation.
    $docblock = "<?php /**\n * abort(404, 'x') and ['error' => 'y'] are bad.\n */\nfunction f() {}";
    $stripped = stripComments($docblock);
    $docHits = checkE5($stripped);
    $docClean = $docHits === [];

    printf("  %s  DOC  docblocks are not scanned\n", $docClean ? 'PASS' : 'FAIL');

    if (!$docClean) {
        $failures++;
    }

    echo $failures === 0
        ? "\nSELFTEST PASS — all 6 checks go red on a violation and stay green on a compliant sample.\n"
        : "\nSELFTEST FAIL — {$failures} check(s) could not distinguish a violation from a compliant sample.\n";

    exit($failures === 0 ? 0 : 1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Tree check
// ─────────────────────────────────────────────────────────────────────────────

$violations = [];
$scanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $appDir))), '/');
    $code = stripComments((string) file_get_contents($path));
    $scanned++;

    foreach (CHECKS as $id => $fn) {
        foreach ($fn($code) as [$line, $message]) {
            $key = $relative . '#' . $id;

            if (array_key_exists($key, ACCEPTED)) {
                continue;
            }

            $violations[] = sprintf('  %s:%d  %s', $relative, $line, $message);
        }
    }
}

// Ratchet entries must still describe something real. An entry whose violation has
// been fixed is stale and must be deleted, or it silently licenses a future
// re-introduction of the same defect.
$stale = [];

foreach (ACCEPTED as $key => $reason) {
    [$relative, $id] = explode('#', $key, 2);
    $path = $appDir . '/' . $relative;

    if (!is_file($path)) {
        $stale[] = "  {$key} — file no longer exists";

        continue;
    }

    $code = stripComments((string) file_get_contents($path));

    if (CHECKS[$id]($code) === []) {
        $stale[] = "  {$key} — no longer violated; remove the entry";
    }
}

echo "verify-error-envelope: {$scanned} file(s) scanned\n";

if ($violations !== []) {
    echo "\n§66 envelope violations:\n" . implode("\n", $violations) . "\n";
}

if ($stale !== []) {
    echo "\nStale ratchet entries:\n" . implode("\n", $stale) . "\n";
}

if ($violations !== [] || $stale !== []) {
    echo "\nFAIL\n";
    exit(1);
}

echo "OK — every JSON error body is the §66 envelope, and every exception is reasoned.\n";
exit(0);
