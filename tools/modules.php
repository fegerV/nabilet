<?php

declare(strict_types=1);

/**
 * Module graph inspector.
 *
 * Usage:
 *     php tools/modules.php            # boot order + enabled/disabled table
 *     php tools/modules.php --validate # exit non-zero if the graph is broken
 *
 * This is the check that runs before a release: a broken dependency graph must be
 * caught here, not by a "class not found" in production.
 */
require __DIR__ . '/../tests/autoload.php';

use Nabilet\Core\Modules\ModuleManager;

$root = dirname(__DIR__);

$manager = new ModuleManager([
    $root . '/app/Modules',
    $root . '/plugins',
]);

$failed = $manager->discover();

$validateOnly = in_array('--validate', $argv, true);

echo "\nNABILET Core — module graph\n";
echo str_repeat('─', 74), "\n";

if ($failed !== []) {
    echo "\n  Broken manifests (skipped): " . implode(', ', $failed) . "\n";
}

$all = $manager->all();

if ($all === []) {
    echo "\n  No modules discovered.\n\n";
    exit($validateOnly ? 1 : 0);
}

$problems = $manager->validate();

printf(
    "\n  %-14s %-8s %-9s %-6s %s\n",
    'MODULE',
    'VERSION',
    'STATUS',
    'PRIO',
    'REQUIRES'
);
echo '  ' . str_repeat('─', 72), "\n";

foreach ($all as $name => $manifest) {
    printf(
        "  %-14s %-8s %-9s %-6d %s\n",
        $name,
        $manifest->version,
        $manager->isEnabled($name) ? 'enabled' : 'disabled',
        $manifest->priority,
        $manifest->requires === [] ? '—' : implode(', ', $manifest->requires)
    );
}

echo "\n";

if ($problems !== []) {
    echo "  PROBLEMS\n";
    foreach ($problems as $problem) {
        printf("    [%s] %s\n", $problem['type'], $problem['message']);
    }
    echo "\n";
    echo "  FAIL — module graph is invalid.\n\n";
    exit(1);
}

$order = $manager->bootOrder();

echo "  Boot order (" . count($order) . " enabled modules):\n";
foreach ($order as $i => $name) {
    printf("    %2d. %s\n", $i + 1, $name);
}

$totalHooks = array_unique(array_merge(
    ...array_map(static fn ($m): array => $m->provides, array_values($all))
));

echo "\n  Declared hook events: " . count($totalHooks) . "\n";

if ($validateOnly) {
    echo "\n  PASS — module graph is valid.\n\n";
}

echo "\n";
exit(0);
