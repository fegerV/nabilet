<?php

declare(strict_types=1);

/**
 * Module structure integrity.
 *
 * Usage:
 *     php tools/verify-module-structure.php
 *
 * WHY THIS EXISTS
 *   `app/Modules/` is supposed to be a set of declared modules: one directory per
 *   module, each with a `module.json` that the registry (`tools/modules.php`) and
 *   `config/nabilet.php` read. A merged branch added code under directory names
 *   that no manifest declares, and nothing noticed — `modules.php --validate`
 *   reads the manifests that exist and is therefore blind to a directory that has
 *   none. The graph was valid and 32 files were outside it.
 *
 *   Two consequences, both real:
 *
 *   * `app/Modules/Carts/` duplicates `app/Modules/Cart/`. The registered module
 *     holds the domain and the manifest; the unregistered one holds the models,
 *     controller, resources, provider and routes. One logical module, two
 *     directories, only one of them known to the registry.
 *   * `app/Modules/Core/` (19 files) is wired into `config/nabilet.php` and
 *     imported by 18 other files, yet has no manifest — and its name sits next to
 *     `app/Core`, which is the framework-agnostic kernel and a completely
 *     different thing. Two meanings for one word.
 *
 *   `app/Modules/Content/` (6 models) and `app/Modules/System/` (7 models) have no
 *   manifest and are referenced from nowhere outside themselves. That is what dead
 *   code looks like, and it is reported here rather than deleted: this tool cannot
 *   see dynamic loading, and a machine without `vendor/` cannot run the app to
 *   find out. Deleting them is a decision, not a cleanup.
 *
 * WHAT IT CHECKS
 *   1. Every directory under `app/Modules/` has a `module.json`.
 *   2. Every `module.json` name matches its directory (case-insensitively).
 *   3. Every `App\Modules\X\…` namespace used anywhere resolves to a directory
 *      that exists — a dangling one is a startup fatal, not a style issue.
 *   4. Which module directories are referenced from nowhere outside themselves
 *      (reported as candidates for deletion, never acted on).
 *
 * A GREEN RESULT IS NOT A CLAIM THAT THE MODULES WORK. Nothing here executes.
 */

// Forward-slash normalised: dirname() returns backslashes on Windows and every
// path from the iterator is normalised, so mixing them silently breaks the
// `str_replace($root . '/', ...)` used to print relative paths.
$root = str_replace('\\', '/', dirname(__DIR__));
$modulesDir = $root . '/app/Modules';

/**
 * Directories that legitimately have no `module.json`.
 *
 * This is a ratchet with reasons, not a silencer. Each entry is a decision that
 * has not been made yet; removing a directory or giving it a manifest means
 * deleting its entry, and anything unlisted fails the build.
 *
 * @var array<string, string>
 */
const UNREGISTERED_ALLOWED = [
    'Carts' => 'duplicates the registered module `Cart` (singular), which holds the '
        . 'manifest and the domain while this one holds models/controller/provider/routes. '
        . 'Consolidate into `Cart`, then delete this entry.',
    'Core' => 'alive and wired into config/nabilet.php, imported by 18 files, but has no '
        . 'manifest. Its name also collides with app/Core, the framework-agnostic kernel. '
        . 'Decide: give it a manifest, rename it, or distribute its models into the '
        . 'registered Organizations/Users/Auth modules.',
    'Content' => '6 models, referenced from nowhere outside this directory, no manifest, '
        . 'not in config. Candidate for deletion; needs a dynamic-loading check first.',
    'System' => '7 models, referenced from nowhere outside this directory, no manifest, '
        . 'not in config. Candidate for deletion; needs a dynamic-loading check first.',
];

/**
 * @return list<string>
 */
function moduleDirectories(string $modulesDir): array
{
    $found = [];

    foreach (scandir($modulesDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (is_dir($modulesDir . '/' . $entry)) {
            $found[] = $entry;
        }
    }

    sort($found);

    return $found;
}

/**
 * Every file under the given roots, as forward-slash absolute paths.
 *
 * @param list<string> $roots
 *
 * @return list<string>
 */
function phpFilesUnder(array $roots): array
{
    $found = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
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

echo "\nNABILET Core — module structure\n";
echo str_repeat('─', 74), "\n\n";

$directories = moduleDirectories($modulesDir);

if ($directories === []) {
    fwrite(\STDERR, "  FAIL  no module directories found under app/Modules/. A green run\n"
        . "        here would mean nothing.\n\n");

    exit(1);
}

printf("  Modules: %d directories under app/Modules/\n\n", count($directories));

$failed = false;

// ── 1. Every directory must be declared ────────────────────────────────────────
$unregistered = [];

foreach ($directories as $name) {
    if (is_file($modulesDir . '/' . $name . '/module.json')) {
        continue;
    }

    if (isset(UNREGISTERED_ALLOWED[$name])) {
        continue;
    }

    $unregistered[] = $name;
}

if ($unregistered !== []) {
    $failed = true;
    echo "  Directories with no module.json, not in UNREGISTERED_ALLOWED:\n\n";
    foreach ($unregistered as $name) {
        printf("    app/Modules/%s\n", $name);
    }
    echo "\n    Either add a module.json, or record why it has none. The registry\n";
    echo "    cannot see a module it has no manifest for.\n\n";
}

// ── 2. Manifest name must match its directory ─────────────────────────────────
$nameMismatches = [];

foreach ($directories as $name) {
    $manifest = $modulesDir . '/' . $name . '/module.json';

    if (! is_file($manifest)) {
        continue;
    }

    $decoded = json_decode((string) file_get_contents($manifest), true);

    if (! is_array($decoded)) {
        $nameMismatches[] = sprintf('%s: module.json is not valid JSON', $name);

        continue;
    }

    $declared = $decoded['name'] ?? null;

    if (! is_string($declared)) {
        $nameMismatches[] = sprintf('%s: module.json has no string "name"', $name);

        continue;
    }

    if (strtolower($declared) !== strtolower($name)) {
        $nameMismatches[] = sprintf('%s: declares name "%s"', $name, $declared);
    }
}

if ($nameMismatches !== []) {
    $failed = true;
    echo "  module.json name does not match its directory:\n\n";
    foreach ($nameMismatches as $line) {
        echo "    $line\n";
    }
    echo "\n";
}

// ── 3. Every Modules\X namespace must resolve to a directory ──────────────────
//
// All three roots are checked, not just `App\Modules\`. The tree is currently
// split across them — 86 files say `Nabilet\Modules\`, 78 say `App\Modules\` and
// 40 say `NabileT\Modules\` — and a check that watches only one root is blind to
// two thirds of the damage. An earlier draft of this tool did exactly that and
// reported 25 of 34 modules as "referenced from nowhere", which was false: the
// registered modules are referenced as `Nabilet\Modules\X\`, not `App\Modules\X\`.
const MODULE_NAMESPACE_ROOTS = ['Nabilet\\Modules\\', 'App\\Modules\\', 'NabileT\\Modules\\'];

$files = phpFilesUnder([
    $root . '/app',
    $root . '/tests',
    $root . '/config',
    $root . '/routes',
    $root . '/database',
    $root . '/bootstrap',
]);

// Read each file once. The per-module scan below walks the same files for each of
// 34 directories, and re-reading them inside that loop is 34x the I/O for nothing.
$sources = [];

foreach ($files as $file) {
    $sources[$file] = (string) file_get_contents($file);
}

$dangling = [];

foreach ($sources as $file => $src) {
    foreach (MODULE_NAMESPACE_ROOTS as $nsRoot) {
        // preg_quote escapes the backslashes to `\\`, which is exactly what the
        // regex needs to match a literal backslash in a fully-qualified name.
        $pattern = '/' . preg_quote($nsRoot, '/') . '(\w+)\\\\/';

        if (! preg_match_all($pattern, $src, $m)) {
            continue;
        }

        foreach (array_unique($m[1]) as $module) {
            if (is_dir($modulesDir . '/' . $module)) {
                continue;
            }

            $dangling[sprintf(
                '%s -> %s%s',
                str_replace($root . '/', '', $file),
                $nsRoot,
                $module
            )] = true;
        }
    }
}

if ($dangling !== []) {
    $failed = true;
    echo "  Namespaces referencing a module directory that does not exist:\n\n";
    foreach (array_keys($dangling) as $line) {
        echo "    $line\n";
    }
    echo "\n    This is a startup fatal, not a style problem: the class cannot load.\n\n";
}

// ── 4. Modules referenced from nowhere outside themselves ─────────────────────
//
// A module is referenced if a PHP file outside it names it, or if another
// module's manifest lists it in `requires`. Ignoring the manifests made this list
// wrong: `Events` declares `"requires": ["organizations", "venues"]`, so those two
// are wired into the graph even though no PHP file names them.
$requiredByManifest = [];

foreach ($directories as $name) {
    $manifest = $modulesDir . '/' . $name . '/module.json';

    if (! is_file($manifest)) {
        continue;
    }

    $decoded = json_decode((string) file_get_contents($manifest), true);

    foreach ((array) ($decoded['requires'] ?? []) as $dependency) {
        if (is_string($dependency)) {
            $requiredByManifest[strtolower($dependency)] = true;
        }
    }
}

$orphans = [];

foreach ($directories as $name) {
    if (isset($requiredByManifest[strtolower($name)])) {
        continue;
    }

    $needles = array_map(
        static fn (string $nsRoot): string => $nsRoot . $name . '\\',
        MODULE_NAMESPACE_ROOTS
    );

    $inbound = 0;

    foreach ($sources as $file => $src) {
        if (str_contains($file, '/app/Modules/' . $name . '/')) {
            continue;
        }

        foreach ($needles as $needle) {
            if (str_contains($src, $needle)) {
                $inbound++;

                break;
            }
        }
    }

    if ($inbound === 0) {
        $orphans[$name] = is_file($modulesDir . '/' . $name . '/module.json');
    }
}

if ($orphans !== []) {
    $declaredOnly = array_keys(array_filter($orphans));
    $deadCode = array_keys(array_filter($orphans, static fn (bool $d): bool => ! $d));

    echo "  Referenced from nowhere outside themselves. Reported, never deleted:\n";
    echo "  this tool cannot see dynamic loading and cannot run the app to find out.\n\n";

    if ($declaredOnly !== []) {
        printf("    declared by a manifest but wired into nothing (%d):\n", count($declaredOnly));

        foreach ($declaredOnly as $name) {
            printf("        app/Modules/%s\n", $name);
        }

        echo "\n";
    }

    if ($deadCode !== []) {
        printf("    no manifest AND referenced from nowhere — dead-code candidates (%d):\n", count($deadCode));

        foreach ($deadCode as $name) {
            printf("        app/Modules/%s\n", $name);
        }

        echo "\n";
    }
}

// ── 5. Class-strings in config/nabilet.php must resolve ───────────────────────
//
// `config/nabilet.php` hardcodes nine providers as `::class` strings under
// `App\Modules\…` — the root that has no PSR-4 prefix and cannot load. Nothing
// reads `config('nabilet.modules')` today, so the app does not notice; the moment
// something does, all nine are `Class not found`. A class-string is a claim, and
// this checks it the same way the autoloader would.
$configFile = $root . '/config/nabilet.php';
$unresolvable = [];
$configRoots = [];

if (is_file($configFile)) {
    preg_match_all(
        '/(?:App|Nabilet|NabileT)\\\\Modules\\\\[\w\\\\]+::class/',
        (string) file_get_contents($configFile),
        $classStrings
    );

    foreach (array_unique($classStrings[0]) as $classString) {
        $fqcn = substr($classString, 0, -strlen('::class'));
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);

        $configRoots[$parts[0] . '\\' . ($parts[1] ?? '') . '\\'] = true;

        $relative = substr($fqcn, strlen(strstr($fqcn, 'Modules', true)) + strlen('Modules\\'));
        $expected = $modulesDir . '/' . str_replace('\\', '/', $relative) . '.php';

        if (! is_file($expected)) {
            $unresolvable[] = sprintf('%s — no file at app/Modules/%s.php', $fqcn, str_replace('\\', '/', $relative));

            continue;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($expected), $nm)) {
            $unresolvable[] = sprintf('%s — %s declares no namespace', $fqcn, $relative);

            continue;
        }

        if (trim($nm[1]) !== $namespace) {
            $unresolvable[] = sprintf(
                '%s — the file declares namespace %s (class %s)',
                $fqcn,
                trim($nm[1]),
                $className
            );
        }
    }
}

if ($unresolvable !== []) {
    $failed = true;
    printf(
        "  config/nabilet.php names %d class(es) whose file does not match the name:\n\n",
        count($unresolvable)
    );

    foreach ($unresolvable as $line) {
        echo "    $line\n";
    }

    echo "\n    A class-string is a promise the autoloader has to keep. Nothing reads\n";
    echo "    this array yet, so the failure would be latent rather than visible.\n\n";
}

// The consistency check above cannot see that a namespace has no PSR-4 prefix —
// a file whose namespace matches its own class-string still cannot load if the
// root is unmapped. Say so rather than let "resolves" imply more than it checked.
foreach (array_keys($configRoots) as $usedRoot) {
    if ($usedRoot === MODULE_NAMESPACE_ROOTS[0]) {
        continue;
    }

    printf(
        "  NOTE  config/nabilet.php wires providers under %s — not the canonical\n"
        . "        %s, and a root composer.json does not map. See verify-autoload.php.\n\n",
        $usedRoot,
        MODULE_NAMESPACE_ROOTS[0]
    );
}

if ($failed) {
    echo "  FAIL — the module tree does not match what the registry declares.\n\n";

    exit(1);
}

$orphanCount = count($orphans);

printf(
    "  PASS — %d directories, %d declared by a manifest, %d unregistered (recorded\n"
    . "         with a reason), 0 dangling namespaces, %d referenced from nowhere,\n"
    . "         and every class-string in config/nabilet.php matching its own file.\n\n",
    count($directories),
    count($directories) - count(UNREGISTERED_ALLOWED),
    count(UNREGISTERED_ALLOWED),
    $orphanCount
);

exit(0);
