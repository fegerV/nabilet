<?php

declare(strict_types=1);

/**
 * Autoload integrity: can every declared namespace actually be loaded?
 *
 * Usage:
 *     php tools/verify-autoload.php [--list]
 *
 * WHY THIS EXISTS
 *   A namespace is a claim that a class can be found. Nothing checked that claim,
 *   and a merged branch shipped 118 PHP files under `app/` that no autoloader can
 *   resolve:
 *
 *     * 78 declare `namespace App\Modules\…`, and `composer.json` has no `App\`
 *       PSR-4 prefix at all — only `Nabilet\Core\`, `Nabilet\Modules\`,
 *       `Nabilet\Plugins\` and the two `Database\` roots;
 *     * 40 declare `namespace Nabilet\Modules\…` — a typo. PSR-4 prefix matching
 *       is case-sensitive, so `Nabilet\` does not match `Nabilet\`.
 *
 *   The damage is worse than "these files do not load". Some modules are split
 *   across all three roots, so their own classes cannot see each other:
 *   `Orders` is 6 `Nabilet\` + 7 `App\` + 5 `Nabilet\`, `Tickets` 10/5/5,
 *   `Payments` 7/4/3. Within one module a `Model` cannot reference its `Domain`.
 *
 *   Every gate this project had passed anyway: `lint.php` checks syntax only,
 *   `verify-purity.php` loads the `Domain/` subdirectory of each module and the
 *   pure `app/Core` subdirs (not `Models/`, not `Http/`), and nothing runs the
 *   app because there is no `vendor/` to run it with.
 *
 *   That is the whole point of this file: the class of defect that only a runtime
 *   can normally catch is, for a namespace, decidable statically. So decide it.
 *
 * WHAT IT CHECKS
 *   1. Every PHP file that declares a namespace is covered by a PSR-4 prefix from
 *      `composer.json` — both `autoload.psr-4` and `autoload-dev.psr-4` — with a
 *      case-sensitive match and longest-prefix-wins, as Composer does it.
 *   2. The file's location matches that prefix's directory — a namespace covered
 *      but sitting in the wrong folder is still unloadable.
 *   3. Prefixes that come only from `autoload-dev` are reported, because they do
 *      not exist in a production `--no-dev` install.
 *
 * A GREEN RESULT HERE DOES NOT MEAN THE CODE WORKS. It means the code can be found.
 */

// Normalised to forward slashes: dirname() returns backslashes on Windows, while
// every path built from the directory iterator is forward-slash normalised. Mixing
// the two made every file look "misplaced" — 143 false positives — because the
// strings differed only in separators while printing identically.
$root = str_replace('\\', '/', dirname(__DIR__));
$listMode = in_array('--list', $argv, true);

/**
 * Namespace roots that cannot be loaded, recorded with the reason they are still
 * there. A ratchet: each entry is a decision not yet made, and anything unlisted
 * fails the build. Entries may only be removed, never added without a reason.
 *
 * @var array<string, string>
 */
const UNLOADABLE_ALLOWED = [
    'App\\Modules\\' => '78 files declare this root and composer.json has no `App\\` prefix. '
        . 'Either add the prefix (giving one tree two names) or rewrite the files to '
        . '`Nabilet\\Modules\\`. The second is right: the domain, the tests and the module '
        . 'registry all use `Nabilet\\Modules\\`.',
    'Nabilet\\Modules\\' => '40 files declare this root. It is a typo for `Nabilet\\Modules\\`, '
        . 'and PSR-4 prefix matching is case-sensitive, so it can never resolve. Fix is a '
        . 'rename, not a decision.',
    'Tests\\Feature\\' => '3 files declare `Tests\\Feature\\Api`. composer.json maps '
        . '`Nabilet\\Tests\\`, so the same files under that root would resolve. These also '
        . '`use Tests\\TestCase`, which does not exist (the runner\'s base class is '
        . '`Nabilet\\Tests\\Support\\TestCase`), and they need PHPUnit — so they are inert '
        . 'either way and the runner reports them as not run.',
];

/**
 * A namespace root this tool knows must appear, so a scanner that silently reads
 * nothing fails loudly instead of reporting a clean bill of health.
 *
 * @var list<string>
 */
const REQUIRED_ROOTS = [
    'Nabilet\\Core\\',
    'Nabilet\\Modules\\',
    'Nabilet\\Tests\\',
];

/**
 * @return array{prod: array<string, string>, dev: array<string, string>}
 */
function composerPsr4(string $root): array
{
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $out = ['prod' => [], 'dev' => []];

    foreach (['autoload' => 'prod', 'autoload-dev' => 'dev'] as $section => $bucket) {
        foreach ($composer[$section]['psr-4'] ?? [] as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                $out[$bucket][$prefix] = $root . '/' . rtrim($dir, '/');
            }
        }
    }

    return $out;
}

/**
 * @param list<string> $roots
 *
 * @return list<string>
 */
function phpFilesUnder(array $roots): array
{
    $found = [];

    foreach ($roots as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    sort($found);

    return $found;
}

echo "\nNABILET Core — autoload integrity\n";
echo str_repeat('─', 74), "\n\n";

$psr4 = composerPsr4($root);
$allPrefixes = $psr4['prod'] + $psr4['dev'];

if ($allPrefixes === []) {
    fwrite(\STDERR, "  FAIL  composer.json declares no PSR-4 prefixes. A green run here\n"
        . "        would mean nothing.\n\n");

    exit(1);
}

$files = phpFilesUnder([
    $root . '/app',
    $root . '/tests',
    $root . '/database',
    $root . '/plugins',
]);

printf("  composer.json PSR-4 (prod): %s\n", implode(', ', array_keys($psr4['prod'])) ?: '(none)');
printf("  composer.json PSR-4 (dev):  %s\n", implode(', ', array_keys($psr4['dev'])) ?: '(none)');
printf("  PHP files scanned:          %d\n\n", count($files));

$withNamespace = 0;
$uncovered = [];
$misplaced = [];
$declaredRoots = [];

foreach ($files as $path) {
    $src = (string) file_get_contents($path);

    if (! preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
        continue;
    }

    $withNamespace++;
    $ns = trim($m[1]);
    $relative = str_replace($root . '/', '', $path);

    $nsParts = explode('\\', $ns);
    $declaredRoots[implode('\\', array_slice($nsParts, 0, 2)) . '\\'] = true;

    // Longest matching prefix wins, and the comparison is case-sensitive, exactly
    // as Composer's ClassLoader does it.
    $match = null;

    foreach ($allPrefixes as $prefix => $dir) {
        if (! str_starts_with($ns . '\\', $prefix)) {
            continue;
        }

        if ($match === null || strlen($prefix) > strlen($match[0])) {
            $match = [$prefix, $dir];
        }
    }

    if ($match === null) {
        $parts = explode('\\', $ns);
        $group = implode('\\', array_slice($parts, 0, 2)) . '\\';
        $uncovered[$group][] = $relative;

        continue;
    }

    [$prefix, $dir] = $match;

    // The namespace minus the prefix is the *directory* path; the file's own name
    // supplies the class segment. Omitting that segment is how an earlier draft of
    // this tool reported 114 files as "misplaced" when none of them were.
    $rest = trim(str_replace('\\', '/', substr($ns, strlen($prefix))), '/');
    $expected = $rest === ''
        ? $dir . '/' . basename($path)
        : $dir . '/' . $rest . '/' . basename($path);

    if ($expected !== $path) {
        $misplaced[] = sprintf(
            "%s\n        namespace %s maps to %s",
            $relative,
            $ns,
            str_replace($root . '/', '', $expected)
        );
    }
}

printf("  Files declaring a namespace: %d\n\n", $withNamespace);

// Self-check: a scanner that read nothing must not report success.
$missingRoots = [];

foreach (REQUIRED_ROOTS as $required) {
    if (! isset($declaredRoots[$required])) {
        $missingRoots[] = $required;
    }
}

if ($withNamespace === 0 || $missingRoots !== []) {
    fwrite(\STDERR, sprintf(
        "  FAIL  the scanner read %d namespaced files and never saw %s.\n"
        . "        The scanner is wrong, not the code — fix the walk in phpFilesUnder().\n\n",
        $withNamespace,
        $missingRoots === [] ? 'anything at all' : implode(', ', $missingRoots)
    ));

    exit(1);
}

$failed = false;
$newlyUnloadable = [];

foreach ($uncovered as $group => $paths) {
    if (! isset(UNLOADABLE_ALLOWED[$group])) {
        $newlyUnloadable[$group] = $paths;
    }
}

if ($newlyUnloadable !== []) {
    $failed = true;
    echo "  Namespaces no PSR-4 prefix covers (these classes cannot load):\n\n";

    foreach ($newlyUnloadable as $group => $paths) {
        printf("    %s  (%d files)\n", $group, count($paths));

        if ($listMode) {
            foreach ($paths as $p) {
                printf("        %s\n", $p);
            }
        }
    }

    echo "\n    Either add the prefix to composer.json, or rewrite the namespaces.\n";
    echo "    Two roots for one directory tree is worse than either fix.\n\n";
}

if ($misplaced !== []) {
    $failed = true;
    printf(
        "  Namespace covered by a prefix, but the file is not where that prefix maps (%d):\n\n",
        count($misplaced)
    );

    foreach ($misplaced as $line) {
        echo "    $line\n";
    }

    echo "\n";
}

if ($failed) {
    echo "  FAIL — some declared namespaces cannot be autoloaded.\n\n";

    exit(1);
}

$allowedFiles = 0;

foreach (UNLOADABLE_ALLOWED as $group => $why) {
    $count = count($uncovered[$group] ?? []);
    $allowedFiles += $count;

    printf("  ALLOWED  %-18s %3d file(s) — %s\n", $group, $count, wordwrap($why, 60, "\n" . str_repeat(' ', 33)));
}

echo "\n";

printf(
    "  PASS — every namespace not in UNLOADABLE_ALLOWED is covered by a prefix and\n"
    . "         sits where that prefix maps. %d file(s) remain in %d known-unloadable\n"
    . "         root(s). That list may only shrink.\n\n",
    $allowedFiles,
    count(UNLOADABLE_ALLOWED)
);

exit(0);
