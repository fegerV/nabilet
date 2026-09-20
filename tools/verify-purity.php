<?php

declare(strict_types=1);

/**
 * Domain purity guard — the kernel's "no framework, no I/O" claim, enforced.
 *
 * Usage:
 *     php tools/verify-purity.php
 *
 * WHY THIS EXISTS
 *   The whole testing strategy of this project rests on one claim: the domain
 *   layer can be executed with nothing but a PHP binary — no `vendor/`, no
 *   Composer, no database. That is what makes money arithmetic, transition rules
 *   and concurrency guards provable in a sandbox where Composer has no network.
 *
 *   Nothing was enforcing it. One `use Illuminate\Support\Facades\DB;` inside a
 *   domain class would compile, would lint, and would then be untestable here —
 *   and the failure would only surface on a machine that has `vendor/`, i.e.
 *   in CI or in production, i.e. not where the mistake is cheapest to see.
 *
 * WHAT IT CHECKS, IN TWO HALVES
 *   1. STATIC — the token stream of every pure file is scanned for framework
 *      imports, facades, global helpers, I/O and process calls, and `exit`.
 *   2. DYNAMIC — every class declared in a pure file is actually loaded through
 *      the dependency-free autoloader. This is the half that proves the claim
 *      instead of inferring it: a class with a Laravel parent or interface
 *      cannot resolve, so it fails here rather than in CI.
 *
 * WHY A TOKEN STREAM AND NOT A GREP
 *   The docblocks in this codebase say things like "no Laravel, no I/O". A grep
 *   for `Illuminate` would flag the comments that explain the rule. Comments and
 *   string literals are dropped before scanning, so only real code is judged.
 *
 * TWO TIERS, DELIBERATE
 *   Every guarded file must be free of the framework. Only `Domain/` must be
 *   free of I/O as well: `app/Core/Modules` loads `module.json`, and a manifest
 *   loader that cannot read a file is not a manifest loader. Neither may touch
 *   Laravel.
 *
 * WHAT IS DELIBERATELY NOT FLAGGED
 *   - `Nabilet\Core\*` and `Nabilet\Modules\*` imports, and PHP builtins. The
 *     domain may depend on the kernel; it may not depend on the framework.
 *   - A framework helper called behind `function_exists(...)` with a standalone
 *     fallback. That is an optional integration, not a dependency, and it is
 *     what lets the hook functions run under `tools/` and `tests/`. See
 *     capabilityCheck().
 */

require __DIR__ . '/../tests/autoload.php';

$root = dirname(__DIR__);

/** Directories that must stay free of the framework. */
$pureDirs = [
    'app/Core/Errors',
    'app/Core/Hooks',
    'app/Core/Idempotency',
    'app/Core/Modules',
    'app/Core/StateMachine',
    'app/Core/Support',
    'app/Core/Tenancy',
];

// Every module's Domain/ directory. The module layer outside Domain/ is expected
// to be Laravel-aware (providers, controllers) and is not guarded.
foreach (glob($root . '/app/Modules/*/Domain') ?: [] as $dir) {
    $pureDirs[] = 'app/Modules/' . basename(dirname($dir)) . '/Domain';
}

/** Namespaces that must never be imported into a pure file. */
const FORBIDDEN_NAMESPACES = ['Illuminate', 'Laravel', 'Symfony', 'Nabilet\\Core\\Http'];

/** Facades: `DB::`, `Schema::`, `Cache::` … */
const FORBIDDEN_FACADES = [
    'DB', 'Schema', 'Artisan', 'Cache', 'Redis', 'Log', 'Event', 'Queue', 'Mail',
    'Storage', 'Http', 'Config', 'Auth', 'Gate', 'Validator', 'Request', 'Session',
    'Bus', 'Notification', 'Blade', 'URL', 'File', 'Route',
];

/** Global helpers: `app()`, `config()`, `now()`, `dispatch()` … */
const FORBIDDEN_HELPERS = [
    'app', 'config', 'env', 'request', 'now', 'dispatch', 'event', 'cache',
    'redirect', 'route', 'view', 'abort', 'report', 'logger', 'resolve', 'trans',
];

/** Filesystem, network, process. A domain class must be a pure function of its input. */
const FORBIDDEN_IO = [
    'file_get_contents', 'file_put_contents', 'fopen', 'fclose', 'fwrite', 'fread',
    'unlink', 'mkdir', 'curl_init', 'curl_exec', 'curl_setopt', 'fsockopen',
    'shell_exec', 'proc_open', 'popen', 'passthru', 'system', 'exec',
];

/** Anything that talks to an external store by name. */
const FORBIDDEN_CLASSES = ['PDO', 'mysqli', 'Redis', 'Memcached'];

/**
 * PHP 8 tokenizes a qualified name as ONE token — T_NAME_QUALIFIED for
 * `Foo\Bar`, T_NAME_FULLY_QUALIFIED for `\Foo\Bar`, T_NAME_RELATIVE for
 * `namespace\Bar`. They are NOT T_STRING + T_NS_SEPARATOR any more.
 *
 * This matters more than it looks. A scanner that only accepts the pre-8 shape
 * collects an EMPTY name for every import, so the import check inspects nothing
 * and reports success. The guard would have been a rubber stamp. It was caught
 * only because the load half of this tool independently failed on 68 classes
 * with empty namespaces — a green static check is worthless on its own.
 */
const NAME_TOKENS = [
    \T_STRING, \T_NS_SEPARATOR,
    \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE,
];

function isNameToken(?int $id): bool
{
    return $id !== null && in_array($id, NAME_TOKENS, true);
}

/** `Foo\Bar` and `\Foo\Bar` both mean Bar at the point of use. */
function lastSegment(string $name): string
{
    $pos = strrpos($name, '\\');

    return $pos === false ? $name : substr($name, $pos + 1);
}

/**
 * Which forbidden helpers this file may call, because it asked first.
 *
 * `app/Core/Support/helpers.php` does this:
 *
 *     if (function_exists('app')) { try { app(HookRegistry::class) ... } }
 *     return $fallback ??= new HookRegistry();
 *
 * That is not a framework dependency — it is an optional integration with a
 * standalone fallback, and it is the reason the hook functions work in
 * `tools/` and `tests/` where no container exists. Banning it would be
 * pedantry that breaks a working design; ignoring it entirely would let a real
 * dependency hide behind the same syntax.
 *
 * So the rule is enforceable rather than absolute: a framework helper is
 * permitted only when the file proves at runtime that the framework is there
 * before calling it.
 *
 * @return list<string>
 */
function capabilityCheck(string $file): array
{
    $raw = file_get_contents($file);
    $forgiven = [];

    foreach (array_merge(FORBIDDEN_HELPERS, FORBIDDEN_IO) as $fn) {
        if (str_contains($raw, "function_exists('" . $fn . "')")
            || str_contains($raw, 'function_exists("' . $fn . '")')) {
            $forgiven[] = $fn;
        }
    }

    return $forgiven;
}

/**
 * @return list<array{id: int|null, text: string, line: int}>
 */
function significantTokens(string $file): array
{
    $out = [];
    $line = 1;

    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;

            $out[] = ['id' => $id, 'text' => $text, 'line' => $token[2]];
            $line += substr_count($text, "\n");

            continue;
        }

        $out[] = ['id' => null, 'text' => $token, 'line' => $line];
        $line += substr_count($token, "\n");
    }

    // Comments and string literals are dropped: they describe the rule, they do
    // not have to obey it.
    return array_values(array_filter(
        $out,
        static fn (array $t): bool => ! in_array(
            $t['id'],
            [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_INLINE_HTML,
                \T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE,
                \T_START_HEREDOC, \T_END_HEREDOC, \T_OPEN_TAG, \T_CLOSE_TAG],
            true
        )
    ));
}

/**
 * @param  list<array{id: int|null, text: string, line: int}> $tokens
 * @param  list<string>                                       $forgiven helpers the file guards with function_exists()
 * @return list<array{line: int, kind: string, what: string}>
 */
function scanTokens(array $tokens, bool $forbidIo, array $forgiven): array
{
    $found = [];
    $count = count($tokens);

    $add = static function (array &$found, int $line, string $kind, string $what): void {
        $found[] = ['line' => $line, 'kind' => $kind, 'what' => $what];
    };

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        $prev = $tokens[$i - 1] ?? ['id' => null, 'text' => '', 'line' => 0];
        $next = $tokens[$i + 1] ?? ['id' => null, 'text' => '', 'line' => 0];

        // ── imports ──────────────────────────────────────────────────────────
        if ($t['id'] === \T_USE) {
            if ($next['text'] === '(') {
                continue; // closure `function () use ($x)`, not an import
            }

            $j = $i + 1;
            $fqn = '';

            while ($j < $count) {
                $id = $tokens[$j]['id'];

                if ($id === \T_FUNCTION || $id === \T_CONST) {
                    $j++;

                    continue; // `use function foo\bar` / `use const FOO`
                }

                if (isNameToken($id)) {
                    $fqn .= $tokens[$j]['text'];
                    $j++;

                    continue;
                }

                break;
            }

            if ($fqn !== '') {
                foreach (FORBIDDEN_NAMESPACES as $bad) {
                    if (str_starts_with($fqn, $bad . '\\') || $fqn === $bad) {
                        $add($found, $t['line'], 'framework import', $fqn);
                    }
                }
            }

            continue;
        }

        // ── facades ──────────────────────────────────────────────────────────
        if (isNameToken($t['id']) && ($next['text'] === '::' || $next['id'] === \T_DOUBLE_COLON)
            && in_array(lastSegment($t['text']), FORBIDDEN_FACADES, true)) {
            $add($found, $t['line'], 'facade', lastSegment($t['text']) . '::');

            continue;
        }

        // ── external stores ──────────────────────────────────────────────────
        // BEFORE the helper check on purpose. Both match `name(`, and if the
        // helper check ran first its `continue` would swallow `new PDO(` — which
        // is exactly what happened on the first run of this tool: PDO was in the
        // probe file and was not reported.
        if (isNameToken($t['id']) && in_array(lastSegment($t['text']), FORBIDDEN_CLASSES, true)
            && ($next['text'] === '(' || $next['text'] === '::')) {
            $add($found, $t['line'], 'external store', lastSegment($t['text']));

            continue;
        }

        // ── helpers and I/O ──────────────────────────────────────────────────
        if (isNameToken($t['id']) && $next['text'] === '(') {
            $name = lastSegment($t['text']);

            $isIo = in_array($name, FORBIDDEN_IO, true);

            if (in_array($name, FORBIDDEN_HELPERS, true) || $isIo) {
                if ($isIo && ! $forbidIo) {
                    continue; // see the two-tier note at the top of this file
                }

                if (in_array($name, $forgiven, true)) {
                    continue; // behind function_exists() — see capabilityCheck()
                }

                // A method call is not a global helper: `$this->logger()` and
                // `self::now()` are fine and must not be reported.
                $isMember = in_array($prev['text'], ['->', '?->', '::', '\\'], true);
                $isDeclaration = in_array($prev['id'], [\T_FUNCTION, \T_NEW, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true);

                if (! $isMember && ! $isDeclaration) {
                    $add($found, $t['line'], $isIo ? 'I/O or process call' : 'framework helper', $name . '()');
                }
            }

            continue;
        }

        // ── exit / die ───────────────────────────────────────────────────────
        if ($t['id'] === \T_EXIT) {
            $add($found, $t['line'], 'terminates the process', 'exit/die');
        }
    }

    // `Redis` is both a facade and an external store, so one line can be caught
    // twice. Deduplicate rather than report the same defect as two.
    $unique = [];
    $seen = [];

    foreach ($found as $v) {
        $key = $v['line'] . '|' . $v['kind'] . '|' . $v['what'];

        if (! isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $v;
        }
    }

    return $unique;
}

/**
 * The namespace + declared type of a file, so it can be loaded by name.
 *
 * @param  list<array{id: int|null, text: string, line: int}> $tokens
 * @return array{namespace: string, type: string|null, name: string|null}
 */
function declarationOf(array $tokens): array
{
    $namespace = '';
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] === \T_NAMESPACE) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (isNameToken($tokens[$j]['id'])) {
                    $namespace .= $tokens[$j]['text'];

                    continue;
                }

                break;
            }

            continue;
        }

        if (in_array($tokens[$i]['id'], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true)) {
            $prev = $tokens[$i - 1] ?? ['id' => null, 'text' => ''];

            // `new class {}` is anonymous. `Foo::class` is a name, not a
            // declaration — and treating it as one made the scanner walk forward
            // past the `::class` and adopt some unrelated identifier as the
            // declared type. helpers.php was reported as declaring HookRegistry
            // because of `app(HookRegistry::class)`.
            if ($prev['id'] === \T_NEW || $prev['text'] === '::' || $prev['id'] === \T_DOUBLE_COLON) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if ($name !== null && $name['id'] === \T_STRING) {
                return [
                    'namespace' => $namespace,
                    'type' => $tokens[$i]['text'],
                    'name' => $name['text'],
                ];
            }
        }
    }

    return ['namespace' => $namespace, 'type' => null, 'name' => null];
}

// ── run ──────────────────────────────────────────────────────────────────────

echo "\nNABILET Core — domain purity guard\n";
echo str_repeat('─', 74), "\n\n";

$files = [];

foreach ($pureDirs as $dir) {
    foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $file) {
        $files[] = $file;
    }
}

sort($files);

$violations = [];
$unloadable = [];
$loaded = 0;
$declared = 0;

foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $tokens = significantTokens($file);

    // Two tiers, deliberately. Every guarded file must be free of the framework;
    // only Domain/ must additionally be free of I/O. app/Core/Modules reads
    // module.json — a manifest loader that cannot read files is not a manifest
    // loader — but it must still never touch Laravel.
    $isDomain = str_contains($relative, '/Domain/');

    foreach (scanTokens($tokens, $isDomain, capabilityCheck($file)) as $v) {
        $violations[] = ['file' => $relative] + $v;
    }

    $declaration = declarationOf($tokens);

    if ($declaration['name'] === null) {
        continue; // functions-only file, e.g. app/Core/Support/helpers.php
    }

    $declared++;
    $fqcn = ($declaration['namespace'] === '' ? '' : $declaration['namespace'] . '\\') . $declaration['name'];

    // Resolving the class is what proves the claim: a Laravel parent or interface
    // cannot be found, and PHP raises an Error at autoload time. Caught, so that
    // a violation is a line of output instead of a fatal that kills the run and
    // hides every file after it.
    try {
        $ok = match ($declaration['type']) {
            'interface' => interface_exists($fqcn),
            'trait' => trait_exists($fqcn),
            'enum' => enum_exists($fqcn),
            default => class_exists($fqcn),
        };
    } catch (\Throwable $e) {
        $ok = false;
        $unloadable[] = ['file' => $relative, 'class' => $fqcn, 'error' => $e->getMessage()];

        continue;
    }

    if ($ok) {
        $loaded++;
    } else {
        $unloadable[] = ['file' => $relative, 'class' => $fqcn, 'error' => 'does not resolve'];
    }
}

printf("  Files guarded:        %d\n", count($files));
printf("  Classes declared:     %d\n", $declared);
printf("  Loaded with no vendor: %d\n", $loaded);

if ($violations === [] && $unloadable === []) {
    printf("\n  Clean: no framework import, facade, helper, I/O or process call.\n");
} else {
    foreach ($violations as $v) {
        printf("  FAIL  %s:%d  %s — %s\n", $v['file'], $v['line'], $v['kind'], $v['what']);
    }

    foreach ($unloadable as $u) {
        printf("  FAIL  %s\n", $u['file']);
        printf("        declared %s — %s\n", $u['class'], $u['error']);
        printf("        it cannot load without vendor/, so it cannot be tested here\n");
    }
}

echo "\n" . str_repeat('─', 74), "\n";
printf(
    "  %d files, %d violations\n",
    count($files),
    count($violations) + count($unloadable)
);

echo "\n";
exit($violations === [] && $unloadable === [] ? 0 : 1);
