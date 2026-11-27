<?php

declare(strict_types=1);

/**
 * Гейт согласованности версий релиза (без vendor/ и без Laravel).
 *
 *   php tools/verify-release-version.php [--version=X.Y.Z] [--allow-unreleased]
 *
 * Обычный CI вызывает его с --allow-unreleased (проверяется только синхрон
 * config/package). Сборка архива и релиз по тегу вызывают строго: раздел
 * CHANGELOG с датой обязан существовать.
 */

require __DIR__ . '/lib/release-version.php';

$repo = dirname(__DIR__);
$expected = null;
$allowUnreleased = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--version=')) {
        $expected = substr($arg, strlen('--version='));
    } elseif ($arg === '--allow-unreleased') {
        $allowUnreleased = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php tools/verify-release-version.php [--version=X.Y.Z] [--allow-unreleased]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\n");
        exit(2);
    }
}

try {
    $version = nabilet_release_assert($repo, $expected, $allowUnreleased);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("OK — версия релиза %s согласована (config, package.json, CHANGELOG).\n", $version);
exit(0);
