<?php

declare(strict_types=1);

/**
 * Dependency-free test runner.
 *
 * Usage:
 *     php tests/run.php            # run everything
 *     php tests/run.php Money      # run only test classes whose name contains "Money"
 *
 * Exits non-zero on failure so it can gate a build.
 */
require __DIR__ . '/autoload.php';

$filter = $argv[1] ?? null;

$files = glob(__DIR__ . '/Unit/*Test.php') ?: [];
sort($files);

$totalPassed = 0;
$totalFailed = 0;
$totalAssertions = 0;
$allFailures = [];
$startedAt = microtime(true);

echo "\n";
echo "NABILET Core — kernel test suite\n";
echo str_repeat('─', 68), "\n";

foreach ($files as $file) {
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

if ($allFailures !== []) {
    echo "\nFailures:\n";
    foreach ($allFailures as $i => $failure) {
        printf("  %2d) %s\n", $i + 1, $failure);
    }
    echo "\n";
}

printf(
    "%s  %d test methods passed, %d failed, %d assertions in %.0f ms\n\n",
    $totalFailed === 0 ? 'PASS' : 'FAIL',
    $totalPassed,
    $totalFailed,
    $totalAssertions,
    $elapsed
);

exit($totalFailed === 0 ? 0 : 1);
