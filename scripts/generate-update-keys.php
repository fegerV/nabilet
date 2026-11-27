<?php

declare(strict_types=1);

/**
 * Generate the release signing keypair for automatic updates.
 *
 *   php scripts/generate-update-keys.php --out-dir=/secure/path
 *
 * Writes:
 *   update-private.pem  — KEEP SECRET. Put it into CI as the base64/PEM secret
 *                         NABILET_UPDATE_PRIVATE_KEY_PEM, never into the repo.
 *   update-public.pem   — paste its contents into config/updates.php (or the
 *                         UPDATES_PUBLIC_KEY env var as a single line with \n).
 *
 * The private key file is never added to git; the generated directory is
 * protected by .gitignore. Treat an already-published private key as
 * compromised: rotate the pair and re-sign the current release.
 */

$outDir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out-dir=')) {
        $outDir = substr($arg, strlen('--out-dir='));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/generate-update-keys.php --out-dir=/secure/path\n");
        exit(0);
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\n");
        exit(2);
    }
}

if ($outDir === null || $outDir === '') {
    fwrite(STDERR, "Укажите --out-dir (каталог вне репозитория или игнорируемый git).\n");
    exit(2);
}

try {
    if (! is_dir($outDir) && ! mkdir($outDir, 0700, true) && ! is_dir($outDir)) {
        throw new RuntimeException("Не удалось создать каталог: {$outDir}");
    }
    $outDir = rtrim((string) realpath($outDir), '/\\');

    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = @openssl_pkey_new($options);
    $confPath = null;
    if ($key === false) {
        // Some PHP builds point at a missing openssl.cnf. A minimal config is
        // enough for key generation and avoids a hard dependency on that file.
        $confPath = sys_get_temp_dir() . '/nabilet-openssl-' . bin2hex(random_bytes(4)) . '.cnf';
        $conf = $confPath;
        file_put_contents($conf, "[req]\ndistinguished_name = dn\n[dn]\n[openssl_init]\n");
        register_shutdown_function(static function () use ($conf): void {
            @unlink($conf);
        });
        $options['config'] = $conf;
        $key = @openssl_pkey_new($options);
    }
    if ($key === false) {
        throw new RuntimeException('openssl_pkey_new: не удалось сгенерировать ключ. Проверьте расширение openssl.');
    }

    $privatePem = '';
    $exportOptions = $confPath === null ? [] : ['config' => $confPath];
    if (! @openssl_pkey_export($key, $privatePem, null, $exportOptions)) {
        throw new RuntimeException('Не удалось экспортировать приватный ключ.');
    }
    $details = openssl_pkey_get_details($key);
    if ($details === false || ! isset($details['key']) || ! is_string($details['key'])) {
        throw new RuntimeException('Не удалось экспортировать публичный ключ.');
    }
    $publicPem = $details['key'];

    $privatePath = $outDir . '/update-private.pem';
    $publicPath = $outDir . '/update-public.pem';
    if (file_put_contents($privatePath, $privatePem, LOCK_EX) === false
        || file_put_contents($publicPath, $publicPem, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать ключи.');
    }
    @chmod($privatePath, 0600);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'private' => $privatePath,
        'public' => $publicPath,
        'public_pem' => $publicPem,
        'next' => [
            'ci_secret' => 'NABILET_UPDATE_PRIVATE_KEY_PEM (содержимое update-private.pem)',
            'app' => 'config/updates.php → public_key или NABILET_UPDATE_PUBLIC_KEY=(содержимое update-public.pem)',
        ],
        'warning' => 'Приватный ключ не должен попадать в git, архив релиза или логи.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
