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
 *
 * Второй гейт — `tools/scan-implicit-nullable.php` (§3.23.7 спека): PHP 8.4
 * помечает неявно nullable-параметры (`int $x = null`) как Deprecated, но
 * `php -l` возвращает ноль и предупреждение уходит только в stderr при
 * исполнении файла — линтер такого не видит. Сканер разбирает параметры
 * токенайзером и падает сборку, если такое объявление вернулось.
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

    /* ── Гейт 2: неявно nullable-параметры (PHP 8.4 Deprecated, §3.23.7) ──── */
    // `php -l` возвращает ноль даже при таком объявлении: предупреждение
    // уходит в stderr только при исполнении файла, поэтому линтер сам его
    // не видит. Сканер разбирает параметры токенайзером.
    $scan = __DIR__ . '/scan-implicit-nullable.php';
    if (is_file($scan)) {
        $scanOutput = [];
        $scanExit = 0;
        exec(sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($scan), escapeshellarg($root)), $scanOutput, $scanExit);

        echo "NABILET Core — implicit nullable parameters\n";
        echo str_repeat('─', 74), "\n";
        foreach ($scanOutput as $line) {
            echo '  ', $line, "\n";
        }

        if ($scanExit === 0) {
            echo "  PASS — implicit nullable parameters: 0.\n\n";
            exit(0);
        }

        echo "\n  FAIL — PHP 8.4 deprecated implicit nullable parameters.\n";
        echo "  Fix: declare the type explicitly nullable, e.g. `?int \$x = null`.\n\n";
        exit(1);
    }

    exit(0);
}

echo "\n  SYNTAX ERRORS\n";
foreach ($errors as $path => $message) {
    printf("    %s\n      %s\n", str_replace($root, '.', $path), $message);
}

printf("\n  FAIL — %d file(s) with syntax errors.\n\n", count($errors));
exit(1);
