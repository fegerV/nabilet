<?php

declare(strict_types=1);

/**
 * Syntax linter.
 *
 * Usage:
 *     php tools/lint.php
 *
 * Runs `php -l` over every PHP file in the project (excluding vendor and
 * storage). Exits non-zero on the first syntax error so it can gate a build.
 */
$root = dirname(__DIR__);

$skipDirs = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skipDirs): bool {
            if (! $file->isDir()) {
                return true;
            }

            return ! in_array($file->getFilename(), array_map(
                static fn (string $p): string => basename($p),
                $skipDirs
            ), true);
        }
    )
);

$checked = 0;
$errors = [];

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $output = [];
    $exitCode = 0;
    exec(sprintf('php -l %s 2>&1', escapeshellarg($path)), $output, $exitCode);

    $checked++;

    if ($exitCode !== 0) {
        $errors[$path] = implode("\n", $output);
    }
}

echo "\nNABILET Core — syntax lint\n";
echo str_repeat('─', 74), "\n";
printf("  Files checked: %d\n", $checked);

if ($errors === []) {
    echo "  No syntax errors.\n\n";
    exit(0);
}

echo "\n  SYNTAX ERRORS\n";
foreach ($errors as $path => $message) {
    printf("    %s\n      %s\n", str_replace($root, '.', $path), $message);
}

printf("\n  FAIL — %d file(s) with syntax errors.\n\n", count($errors));
exit(1);
