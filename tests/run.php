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
 *   This runner executes `tests/Unit/*Test.php`. The `tests/Feature/` suite drives
 *   the framework through `Illuminate\…` and needs a live MySQL, so this runner
 *   does not load it. What is NOT acceptable is doing that silently.
 *
 *   Three files under `tests/Feature/Api/` existed while this runner printed "PASS"
 *   and a total, and nothing in the output suggested that part of the suite on disk
 *   had never been loaded. A green result that quietly excludes files is worse than
 *   a red one: it is a claim about coverage that the run does not support.
 *
 *   So every `*Test.php` under `tests/` is discovered, and anything this runner does
 *   not execute is named explicitly and counted in the summary.
 *
 *   It used to add "`vendor/` … is absent here, so these files have never been
 *   executed". THAT WAS FALSE — `vendor/bin/phpunit` (11.5.56) and
 *   `vendor/bin/phpstan` are installed in this checkout. The sentence turned a real,
 *   unaddressed failure into an environmental excuse, and the Feature suite stayed
 *   unrun for long enough that a stale test (`payment.canceled` asserted as `failed`)
 *   drifted out of sync with the A12 handler split without anyone noticing.
 *
 *   Now the runner detects PHPUnit and says what is actually true. `--strict` goes
 *   further: it RUNS PHPUnit instead of merely naming the files it skipped, so a
 *   build gate cannot pass on a suite that was never executed.
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

// PHPUnit is what actually runs `tests/Feature/`. Detect it instead of assuming
// it is missing — the previous hard-coded "vendor/ is absent" claim was false and
// concealed an unrun suite (see the file docblock).
$phpunitBin = __DIR__ . '/../vendor/bin/phpunit';
$phpunitAvailable = is_file($phpunitBin);

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
    printf("\n  %d test file(s) on disk were NOT RUN by this runner:\n\n", count($notRunnable));

    foreach ($notRunnable as $path) {
        printf("    NOT RUN  %s\n", relativeToRoot($path));
    }

    if ($phpunitAvailable) {
        echo "\n  These files drive the framework and need a live MySQL, so this\n";
        echo "  dependency-free runner does not load them. They ARE runnable here —\n";
        echo "  PHPUnit is installed:\n\n";
        echo "      php vendor/bin/phpunit\n\n";
        echo "  Do not read the PASS below as coverage of the files listed above.\n";
    } else {
        echo "\n  `vendor/bin/phpunit` was not found, so these files cannot be executed in\n";
        echo "  this checkout. Run `composer install`, then `php vendor/bin/phpunit`.\n";
    }
}

if ($allFailures !== []) {
    echo "\nFailures:\n";
    foreach ($allFailures as $i => $failure) {
        printf("  %2d) %s\n", $i + 1, $failure);
    }
    echo "\n";
}

$featureFailed = false;

// --strict must not be satisfiable by naming the files it skipped. If PHPUnit is
// installed, run it; if it is not, the suite genuinely cannot be verified here and
// the run is a failure.
if ($strict && $notRunnable !== []) {
    if ($phpunitAvailable) {
        printf(
            "\nSTRICT: running the %d file(s) this runner skipped, via PHPUnit.\n\n",
            count($notRunnable)
        );

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunitBin) . ' --no-coverage';
        passthru($command, $featureExit);

        $featureFailed = $featureExit !== 0;
    } else {
        printf(
            "STRICT: %d test file(s) were not run, and PHPUnit is not installed to run\n"
            . "them. Run `composer install` first.\n\n",
            count($notRunnable)
        );

        $featureFailed = true;
    }
}

printf(
    "%s  %d test methods passed, %d failed, %d assertions in %.0f ms%s\n\n",
    $totalFailed === 0 && ! $featureFailed ? 'PASS' : 'FAIL',
    $totalPassed,
    $totalFailed,
    $totalAssertions,
    $elapsed,
    $notRunnable === [] ? '' : sprintf(
        ', %d file(s) not run by this runner%s',
        count($notRunnable),
        $strict ? ' (PHPUnit was run under --strict)' : ' — run `php vendor/bin/phpunit`'
    )
);

exit($totalFailed === 0 && ! $featureFailed ? 0 : 1);
