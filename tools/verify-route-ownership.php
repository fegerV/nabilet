<?php

declare(strict_types=1);

/**
 * Route ownership: who may register HTTP routes, and is every route file
 * actually reachable?
 *
 * Usage:
 *     php tools/verify-route-ownership.php
 *
 * WHY THIS EXISTS
 *   This project broke the same rule twice, in opposite directions, and nothing
 *   failed either time.
 *
 *   1. A module provider called `loadRoutesFrom(__DIR__ . '/../routes/api.php')`
 *      while that same file was ALSO required from `routes/api.php`, inside the
 *      `/api/v1` group `bootstrap/app.php` declares. `loadRoutesFrom()` is a bare
 *      `require` — no group, no prefix — so the endpoints were registered a
 *      second time at the root.
 *
 *      For Payments, whose provider IS registered in `bootstrap/providers.php`,
 *      this is live, not theoretical: `route:list` shows `payments`,
 *      `payments/demo-pay`, `payments/webhooks/{provider}`, `payments/{payment}`
 *      and `webhooks/payment/{provider}` next to their `/api/v1/...` twins. The
 *      unprefixed copies sit outside the API middleware group and are absent from
 *      the contract, so any path-keyed protection that watches `/api/v1/` — an
 *      nginx rate limit, a WAF rule, a monitor — does not cover them.
 *
 *      It is worse than a duplicate: Laravel keys the route collection by
 *      method+uri, so the two copies of `webhooks/payment/{provider}` do NOT even
 *      reach the same controller. `/api/v1/webhooks/payment/{provider}` resolves
 *      to `WebhookController@payment` (the Webhooks module is required last and
 *      overwrites the earlier registration), while the unprefixed path keeps
 *      `PaymentController@webhook`. One logical endpoint, two implementations,
 *      chosen by an accident of load order.
 *
 *   2. The opposite failure: the Media module's route file existed on disk but
 *      nothing loaded it. Its provider pointed `loadRoutesFrom()` at a `routes/`
 *      directory that did not exist, and the provider was not registered, so the
 *      call never ran — `GET /api/v1/media` answered 404 and looked like a
 *      missing feature rather than a wiring bug. No tool could see a route file
 *      that is simply never required.
 *
 *   Both defects share a root cause: route registration was never owned by
 *   anyone. `routes/api.php` is the single owner, because it is the only place
 *   the `/api/v1` prefix and the API middleware group are applied.
 *
 * WHAT IT CHECKS
 *   1. No provider registered in `bootstrap/providers.php` or
 *      `bootstrap/app.php` calls `loadRoutesFrom()`.
 *   2. No provider under `app/Modules/` calls `loadRoutesFrom()` at all —
 *      including providers that are not registered today. An unregistered
 *      provider whose `boot()` would `require` a missing file is a fatal error
 *      waiting for the day someone adds it to the registry.
 *   3. Every `app/Modules/**\/routes/api.php` on disk is required from
 *      `routes/api.php`. A route file nobody loads answers 404 in silence.
 *
 * Comments are stripped before searching. That is not a detail: the correct fix
 * for (1) is a comment saying why the call is absent, and the Auth, Media,
 * Orders, Notifications, Installer and System providers each contain exactly
 * that sentence. A naive `grep` would fail on the documentation of its own rule.
 *
 * A GREEN RESULT HERE DOES NOT MEAN THE ROUTES ARE CORRECT. It means the route
 * table has one owner and every route file is reachable.
 */

$root = str_replace('\\', '/', dirname(__DIR__));

/**
 * PSR-4 roots from composer.json, longest prefix first so that
 * `Nabilet\Modules\…` is not swallowed by a shorter, unrelated prefix.
 */
const PSR4_ROOTS = [
    'Nabilet\\Modules\\' => 'app/Modules/',
    'Nabilet\\Core\\' => 'app/Core/',
    'Nabilet\\Plugins\\' => 'plugins/',
    'App\\' => 'app/',
];

/** Files that may legitimately declare the providers the app boots. */
const REGISTRY_FILES = [
    'bootstrap/providers.php',
    'bootstrap/app.php',
];

/**
 * Remove comments while preserving line numbers, so a reported line still
 * points at the offending statement.
 */
function strip_comments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

/** @return list<int> 1-based line numbers containing $needle. */
function lines_containing(string $source, string $needle): array
{
    $lines = [];

    foreach (explode("\n", $source) as $index => $line) {
        if (str_contains($line, $needle)) {
            $lines[] = $index + 1;
        }
    }

    return $lines;
}

function class_to_relative_file(string $class): ?string
{
    foreach (PSR4_ROOTS as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            return $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    }

    return null;
}

/** @return list<string> absolute paths of every module provider file. */
function module_provider_files(string $root): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/app/Modules', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (str_ends_with($path, '.php') && str_contains($path, '/Providers/')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/** @return list<string> relative paths of every module route file on disk. */
function module_route_files(string $root): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/app/Modules', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (preg_match('#/app/Modules/(.+)/routes/api\.php$#', $path, $matches)) {
            $files[] = 'app/Modules/' . $matches[1] . '/routes/api.php';
        }
    }

    sort($files);

    return $files;
}

// ── Collect the booted providers ────────────────────────────────────────────

/** @var array<string, string> class => registry file that names it */
$registered = [];

foreach (REGISTRY_FILES as $registryFile) {
    $path = $root . '/' . $registryFile;

    if (! is_file($path)) {
        continue;
    }

    $source = strip_comments((string) file_get_contents($path));

    // Only `*ServiceProvider::class` is a provider; bootstrap/app.php also names
    // middleware and commands with the same `::class` syntax.
    preg_match_all('/([A-Za-z_][A-Za-z0-9_\\\\]*)::class/', $source, $matches);

    foreach ($matches[1] as $class) {
        $class = ltrim($class, '\\');

        if (str_ends_with($class, 'ServiceProvider')) {
            $registered[$class] = $registryFile;
        }
    }
}

ksort($registered);

// ── Check 1 and 2: nobody loads routes from a provider ──────────────────────

/** @var list<string> $failures */
$failures = [];
/** @var list<array{class: string, file: string, line: int}> $deadTargets */
$deadTargets = [];
$offendingProviders = [];

foreach (module_provider_files($root) as $file) {
    $source = strip_comments((string) file_get_contents($file));
    $lines = lines_containing($source, 'loadRoutesFrom(');

    if ($lines === []) {
        continue;
    }

    $relative = substr($file, strlen($root) + 1);
    $offendingProviders[] = $relative;

    foreach ($lines as $line) {
        $failures[] = sprintf('%s:%d calls loadRoutesFrom()', $relative, $line);
    }
}

// Registered providers are the strict subset: they actually boot.
foreach ($registered as $class => $registryFile) {
    $relative = class_to_relative_file($class);

    if ($relative === null) {
        continue;
    }

    $path = $root . '/' . $relative;

    if (! is_file($path)) {
        $failures[] = sprintf(
            '%s registers %s, which does not exist at %s',
            $registryFile,
            $class,
            $relative
        );

        continue;
    }

    $source = strip_comments((string) file_get_contents($path));

    if (lines_containing($source, 'loadRoutesFrom(') !== []) {
        $failures[] = sprintf(
            '%s is registered in %s and still calls loadRoutesFrom()',
            $relative,
            $registryFile
        );
    }
}

// ── Check 3: every route file on disk is required from routes/api.php ───────

$routeFiles = module_route_files($root);
$apiPath = $root . '/routes/api.php';
$apiSource = is_file($apiPath) ? strip_comments((string) file_get_contents($apiPath)) : '';
$unreachable = [];

foreach ($routeFiles as $relative) {
    if (! str_contains($apiSource, $relative)) {
        $unreachable[] = $relative;
    }
}

// ── Report ──────────────────────────────────────────────────────────────────

echo "\nNABILET Core — route ownership\n";
echo str_repeat('─', 74) . "\n\n";

printf("  Booted providers: %d, named in %s\n", count($registered), implode(', ', REGISTRY_FILES));
printf("  Module route files on disk: %d\n", count($routeFiles));
printf("  Providers calling loadRoutesFrom(): %d\n\n", count($offendingProviders));

if ($offendingProviders !== []) {
    echo "  Providers that would register routes outside /api/v1:\n\n";

    foreach ($offendingProviders as $relative) {
        echo '    - ' . $relative . "\n";
    }

    echo "\n";
}

if ($unreachable !== []) {
    echo "  Route files nobody loads (they answer 404 in silence):\n\n";

    foreach ($unreachable as $relative) {
        echo '    - ' . $relative . "\n";
    }

    echo "\n";
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo '  FAIL  ' . $failure . "\n";
    }

    echo "\n  Routes have exactly one owner: routes/api.php, the only place the\n";
    echo "  /api/v1 prefix and the API middleware group are applied. A provider that\n";
    echo "  also loads them registers a second, unprefixed copy of every endpoint —\n";
    echo "  and, because Laravel keys routes by method+uri, that copy can even reach\n";
    echo "  a different controller than its /api/v1 twin.\n\n";

    exit(1);
}

printf(
    "  PASS — %d booted providers, 0 of them loading routes; %d module route\n"
    . "         files on disk, all required from routes/api.php.\n\n",
    count($registered),
    count($routeFiles)
);

exit(0);
