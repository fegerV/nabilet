<?php

declare(strict_types=1);

/**
 * Проверка артефакта перед публикацией (без Laravel):
 *
 *   php tools/verify-release-artifact.php \
 *     --manifest=release-artifacts/manifest.json \
 *     --expected-version=1.16.2 \
 *     --expected-commit=$GITHUB_SHA \
 *     [--public-key=/path/update-public.pem] [--check-signature]
 *
 * Сверяет SHA-256 sidecar/манифест/файл, commit SHA, версию, структуру public_html,
 * отсутствие секретов, runtime-данных, логов и dev-зависимостей.
 */

$manifestPath = null;
$expectedVersion = null;
$expectedCommit = null;
$publicKey = null;
$checkSignature = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--manifest=')) {
        $manifestPath = substr($arg, strlen('--manifest='));
    } elseif (str_starts_with($arg, '--expected-version=')) {
        $expectedVersion = substr($arg, strlen('--expected-version='));
    } elseif (str_starts_with($arg, '--expected-commit=')) {
        $expectedCommit = substr($arg, strlen('--expected-commit='));
    } elseif (str_starts_with($arg, '--public-key=')) {
        $publicKey = substr($arg, strlen('--public-key='));
    } elseif ($arg === '--check-signature') {
        $checkSignature = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php tools/verify-release-artifact.php --manifest=<path> --expected-version=<v> --expected-commit=<sha> [--public-key=<pem> --check-signature]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\n");
        exit(2);
    }
}

try {
    $errors = [];
    if ($manifestPath === null) {
        throw new RuntimeException('Укажите --manifest.');
    }
    $raw = (string) @file_get_contents($manifestPath);
    $m = json_decode($raw, true);
    if (! is_array($m)) {
        throw new RuntimeException('Манифест не является корректным JSON.');
    }

    $dir = dirname($manifestPath);
    $artifact = $dir . '/' . (string) ($m['artifact'] ?? '');
    if (! is_file($artifact)) {
        throw new RuntimeException('Архив из манифеста не найден: ' . (string) ($m['artifact'] ?? ''));
    }
    $hash = (string) hash_file('sha256', $artifact);
    if ($hash !== strtolower((string) ($m['sha256'] ?? ''))) {
        $errors[] = 'SHA-256 архива не совпадает с манифестом';
    }
    $sidecar = (string) @file_get_contents($artifact . '.sha256');
    if (! str_contains($sidecar, $hash)) {
        $errors[] = 'SHA-256 sidecar не совпадает с архивом';
    }
    if ($expectedVersion !== null && $m['version'] !== $expectedVersion) {
        $errors[] = 'Версия манифеста не совпадает с ожидаемой';
    }
    if ($expectedCommit !== null && $m['commit_sha'] !== $expectedCommit) {
        $errors[] = 'commit SHA манифеста не совпадает с ревизией сборки';
    }

    $zip = new ZipArchive();
    if ($zip->open($artifact) !== true) {
        throw new RuntimeException('Архив не открывается.');
    }
    $required = [
        'public_html/index.php', 'public/index.php', 'vendor/autoload.php', 'dist/index.html',
        'artisan', 'composer.lock', '.env.example', 'scripts/updater-recover.php',
    ];
    // Dev-пакеты определяем по самому архиву: termwind — это prod-зависимость
    // laravel/framework, поэтому жёсткий список имён дал бы ложное срабатывание.
    $lock = json_decode((string) $zip->getFromName('composer.lock'), true);
    $devPackages = [];
    if (is_array($lock)) {
        $prod = [];
        foreach (($lock['packages'] ?? []) as $pkg) {
            if (isset($pkg['name'])) { $prod[] = (string) $pkg['name']; }
        }
        foreach (($lock['packages-dev'] ?? []) as $pkg) {
            $name = isset($pkg['name']) ? (string) $pkg['name'] : '';
            if ($name !== '' && ! in_array($name, $prod, true)) { $devPackages[] = $name; }
        }
    } else {
        $errors[] = 'composer.lock в архиве не читается';
    }

    $seen = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $seen[$name] = true;
        foreach ($devPackages as $pkg) {
            if (str_starts_with($name, 'vendor/' . $pkg . '/')) {
                $errors[] = "dev-зависимость в архиве: {$name}";
            }
        }
        if (str_contains($name, '..') || str_starts_with($name, '/')) {
            $errors[] = "небезопасный путь: {$name}";
        }
        if (preg_match('~(^|/)\.env$~', $name) || preg_match('~(^|/)\.env\.(?!example$)~', $name)) {
            $errors[] = "секрет в архиве: {$name}";
        }
        if (str_starts_with($name, 'storage/') && basename($name) !== '.gitignore') {
            $errors[] = "runtime-данные в архиве: {$name}";
        }
        if (str_starts_with($name, 'bootstrap/cache/') && basename($name) !== '.gitignore') {
            $errors[] = "кэш в архиве: {$name}";
        }
        if (preg_match('~^(tests|tools|docs|node_modules)/~', $name)) {
            $errors[] = "dev-дерево в архиве: {$name}";
        }
    }
    foreach ($required as $entry) {
        if (! isset($seen[$entry])) {
            $errors[] = "отсутствует обязательный файл: {$entry}";
        }
    }
    if (isset($seen['index.php'])) {
        $errors[] = 'index.php в корне архива: нарушен docroot';
    }
    $config = (string) $zip->getFromName('config/nabilet.php');
    if (preg_match("/'version'\s*=>\s*'([^']+)'/", $config, $mm) !== 1 || $mm[1] !== $m['version']) {
        $errors[] = 'версия внутри архива не совпадает с манифестом';
    }
    $zip->close();

    if ($checkSignature) {
        if ($publicKey === null) {
            $errors[] = 'запрошена проверка подписи, но не указан --public-key';
        } elseif (trim((string) ($m['signature'] ?? '')) === '') {
            $errors[] = 'манифест не подписан';
        } else {
            $key = openssl_pkey_get_public((string) @file_get_contents($publicKey));
            $sig = base64_decode((string) $m['signature'], true);
            $canonical = implode("\n", [
                'NABILET-UPDATE-V1',
                'schema=1',
                'version=' . $m['version'],
                'released_at' . '=' . $m['released_at'],
                'channel=' . $m['channel'],
                'artifact=' . $m['artifact'],
                'sha256=' . $m['sha256'],
                'size_bytes=' . $m['size_bytes'],
                'php_min=' . ($m['php']['min'] ?? ''),
                'php_max=' . ($m['php']['max_exclusive'] ?? ''),
                'extensions=' . implode(',', (array) ($m['php']['extensions'] ?? [])),
                'from_versions=' . implode(',', (array) ($m['from_versions'] ?? [])),
                'migrations=' . $m['migrations'],
            ]);
            if ($key === false || $sig === false || openssl_verify($canonical, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
                $errors[] = 'подпись манифеста не прошла проверку';
            }
        }
    }

    if ($errors !== []) {
        fwrite(STDERR, "FAIL: " . implode('; ', $errors) . "\n");
        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "ARTIFACT OK — version %s, commit %s, %d entries, sha256 %s%s\n",
        $m['version'],
        $m['commit_sha'],
        count($seen),
        substr($hash, 0, 12),
        $checkSignature ? ', signature valid' : '',
    ));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
