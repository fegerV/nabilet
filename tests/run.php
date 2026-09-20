<?php

declare(strict_types=1);

/**
 * Dependency-free test runner.
 *
 * Usage:
 *     php tests/run.php                 # run everything under tests/Unit
 *     php tests/run.php Money           # run only test classes whose name contains "Money"
 *     php tests/run.php --strict        # exit non-zero if any test file was not run
 *
 * Exits non-zero on failure so it can gate a build.
 *
 * ACCOUNT FOR EVERY FILE, NOT JUST THE ONES YOU RUN
 *   This runner executes `tests/Unit/*Test.php`. That is a deliberate limitation:
 *   the `tests/Feature/` suite drives the framework through `Illuminate\…`, and
 *   there is no `vendor/` here, so those files cannot be loaded at all.
 *
 *   What is NOT acceptable is doing that silently. Three files under
 *   `tests/Feature/Api/` existed while this runner printed "PASS" and a total, and
 *   nothing in the output suggested that part of the suite on disk had never been
 *   loaded. A green result that quietly excludes files is worse than a red one: it
 *   is a claim about coverage that the run does not support.
 *
 *   So every `*Test.php` under `tests/` is now discovered, and anything this runner
 *   does not execute is named explicitly and counted in the summary. `--strict`
 *   turns those into a failure, for a machine that does have `vendor/` and has no
 *   excuse for skipping them.
 */
require __DIR__ . '/autoload.php';

/**
 * Every `*Test.php` under `tests/`, recursively.
 *
 * @return list<string>
 */
function discoverTestFiles(string $dir): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), 'Test.php')) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/** Path relative to the project root, with forward slashes, for display. */
function relativeToRoot(string $path): string
{
    return str_replace('\\', '/', str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $path));
}

$args = array_slice($argv, 1);
$strict = in_array('--strict', $args, true);
$filter = null;

foreach ($args as $arg) {
    if (! str_starts_with($arg, '--')) {
        $filter = $arg;
    }
}

$unitDir = __DIR__ . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR;

$runnable = [];
$notRunnable = [];

foreach (discoverTestFiles(__DIR__) as $path) {
    if (str_starts_with($path, $unitDir)) {
        $runnable[] = $path;

        continue;
    }

    $notRunnable[] = $path;
}

$totalPassed = 0;
$totalFailed = 0;
$totalAssertions = 0;
$allFailures = [];
$startedAt = microtime(true);

echo "\n";
echo "NABILET Core — kernel test suite\n";
echo str_repeat('─', 68), "\n";

foreach ($runnable as $file) {
    $class = 'Nabilet\\Tests\\Unit\\' . basename($file, '.php');

    if ($filter !== null && ! str_contains($class, $filter)) {
        continue;
    }

    require_once $file;

    if (! class_exists($class)) {
        echo sprintf("  !! %s declared no matching class\n", basename($file));
        $totalFailed++;

        continue;
    }

    /** @var \Nabilet\Tests\Support\TestCase $instance */
    $instance = new $class();
    $result = $instance->run();

    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    $totalAssertions += $result['assertions'];

    $status = $result['failed'] === 0 ? 'OK  ' : 'FAIL';
    printf(
        "  %s  %-42s %2d passed  %2d failed  %3d assertions\n",
        $status,
        (new \ReflectionClass($class))->getShortName(),
        $result['passed'],
        $result['failed'],
        $result['assertions']
    );

    foreach ($instance->failures as $failure) {
        $allFailures[] = $failure;
    }
}

$elapsed = (microtime(true) - $startedAt) * 1000;

echo str_repeat('─', 68), "\n";

if ($notRunnable !== []) {
    printf("\n  %d test file(s) on disk were NOT RUN:\n\n", count($notRunnable));

    foreach ($notRunnable as $path) {
        printf("    SKIP  %s\n", relativeToRoot($path));
    }

    echo "\n  This runner only executes tests/Unit/*Test.php. The files above need\n";
    echo "  `vendor/` (they drive the framework), which is absent here, so they have\n";
    echo "  never been executed in this environment — their presence is not evidence\n";
    echo "  that they pass. Run with --strict on a machine that has vendor/ to turn\n";
    echo "  this into a failure.\n";
}

if ($allFailures !== []) {
    echo "\nFailures:\n";
    foreach ($allFailures as $i => $failure) {
        printf("  %2d) %s\n", $i + 1, $failure);
    }
    echo "\n";
}

printf(
    "%s  %d test methods passed, %d failed, %d assertions in %.0f ms%s\n\n",
    $totalFailed === 0 && ! ($strict && $notRunnable !== []) ? 'PASS' : 'FAIL',
    $totalPassed,
    $totalFailed,
    $totalAssertions,
    $elapsed,
    $notRunnable === [] ? '' : sprintf(
        ', %d file(s) not run',
        count($notRunnable)
    )
);

if ($strict && $notRunnable !== []) {
    printf(
        "STRICT: %d test file(s) were not run. This machine cannot run them; a machine\n"
        . "with vendor/ must.\n\n",
        count($notRunnable)
    );
}

exit($totalFailed === 0 && ! ($strict && $notRunnable !== []) ? 0 : 1);
