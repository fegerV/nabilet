<?php

declare(strict_types=1);

/**
 * Проверка подписи релиза без Laravel и БД (для релизного инженера на хосте):
 *
 *   php tools/verify-release-signature.php \
 *     --manifest=releases/manifest.json \
 *     --public-key=/secure/update-public.pem \
 *     [--artifact=releases/nabilet-hosting-vX.Y.Z.zip]
 *
 * Канонические байты собираются по тому же правилу, что в
 * ReleaseManifest::canonical() и scripts/build-hosting-zip.php. Проверяется
 * также SHA-256 архива, если он передан.
 */

$manifestPath = null;
$keyPath = null;
$artifactPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--manifest=')) {
        $manifestPath = substr($arg, strlen('--manifest='));
    } elseif (str_starts_with($arg, '--public-key=')) {
        $keyPath = substr($arg, strlen('--public-key='));
    } elseif (str_starts_with($arg, '--artifact=')) {
        $artifactPath = substr($arg, strlen('--artifact='));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php tools/verify-release-signature.php --manifest=<path> --public-key=<pem> [--artifact=<zip>]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\n");
        exit(2);
    }
}

try {
    if ($manifestPath === null || $keyPath === null) {
        throw new RuntimeException('Укажите --manifest и --public-key.');
    }
    $raw = (string) @file_get_contents($manifestPath);
    $manifest = json_decode($raw, true);
    if (! is_array($manifest)) {
        throw new RuntimeException('Манифест не является корректным JSON.');
    }

    $version = (string) ($manifest['version'] ?? '');
    $releasedAt = (string) ($manifest['released_at'] ?? '');
    $channel = (string) ($manifest['channel'] ?? '');
    $artifact = (string) ($manifest['artifact'] ?? '');
    $sha = strtolower((string) ($manifest['sha256'] ?? ''));
    $size = (int) ($manifest['size_bytes'] ?? 0);
    $php = $manifest['php'] ?? [];
    $phpMin = (string) ($php['min'] ?? '');
    $phpMax = (string) ($php['max_exclusive'] ?? '');
    $extensions = $php['extensions'] ?? [];
    $fromVersions = $manifest['from_versions'] ?? [];
    $migrations = (int) ($manifest['migrations'] ?? 0);
    $signature = (string) ($manifest['signature'] ?? '');

    if (! is_array($extensions) || ! is_array($fromVersions)) {
        throw new RuntimeException('Поля extensions/from_versions должны быть списками.');
    }
    $extensions = array_values(array_map('strval', $extensions));
    $fromVersions = array_values(array_map('strval', $fromVersions));
    sort($extensions, SORT_STRING);

    if ($signature === '') {
        throw new RuntimeException('Манифест не подписан: автоматическое обновление невозможно.');
    }
    $canonical = implode("\n", [
        'NABILET-UPDATE-V1',
        'schema=1',
        'version=' . $version,
        'released_at=' . $releasedAt,
        'channel=' . $channel,
        'artifact=' . $artifact,
        'sha256=' . $sha,
        'size_bytes=' . $size,
        'php_min=' . $phpMin,
        'php_max=' . $phpMax,
        'extensions=' . implode(',', $extensions),
        'from_versions=' . implode(',', $fromVersions),
        'migrations=' . $migrations,
    ]);

    $pem = (string) @file_get_contents($keyPath);
    if (trim($pem) === '') {
        throw new RuntimeException('Не удалось прочитать публичный ключ.');
    }
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        throw new RuntimeException('Публичный ключ некорректен.');
    }
    $bytes = base64_decode($signature, true);
    if ($bytes === false) {
        throw new RuntimeException('Подпись не в base64.');
    }
    if (openssl_verify($canonical, $bytes, $key, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Подпись манифеста не прошла проверку.');
    }

    $hashNote = 'hash not checked';
    if ($artifactPath !== null) {
        $actual = hash_file('sha256', $artifactPath);
        if ($actual === false || $actual !== $sha) {
            throw new RuntimeException('SHA-256 архива не совпадает с манифестом.');
        }
        $hashNote = 'sha256 ok';
    }

    fwrite(STDOUT, sprintf(
        "OK — версия %s, канал %s, подпись действительна, %s.\n",
        $version,
        $channel,
        $hashNote,
    ));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
