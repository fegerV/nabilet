<?php

declare(strict_types=1);

/**
 * Standalone PSR-4 autoloader for the NABILET kernel.
 *
 * Why hand-rolled instead of Composer's: the kernel (app/Core) and the domain
 * state machines are deliberately free of framework dependencies, so they must be
 * testable with nothing but a PHP binary. That is what makes the riskiest logic in
 * this project — concurrency guards, money arithmetic, transition rules — provable
 * in a sandbox with no Composer network access and no database.
 *
 * In a normal install Composer's autoloader covers these namespaces; this file is
 * the fallback used by tools/ and tests/.
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Nabilet\\Core\\' => __DIR__ . '/../app/Core/',
        'Nabilet\\Modules\\' => __DIR__ . '/../app/Modules/',
        'Nabilet\\Tests\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});

// Mirrors composer.json's "autoload.files": the global hook functions must exist
// before any test that exercises the extension contract.
require_once __DIR__ . '/../app/Core/Support/helpers.php';
