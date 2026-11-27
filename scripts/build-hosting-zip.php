<?php

declare(strict_types=1);

/**
 * Портативный сборщик установочного ZIP для shared hosting.
 *
 *   php scripts/build-hosting-zip.php --version=1.16.0 [--output-dir=releases]
 *
 * Работает на Linux/macOS/Windows без robocopy и без внешнего zip: PHP
 * ZipArchive + composer из lock-файла. Все входные файлы берутся по явному
 * списку — секреты, логи, бэкапы, пользовательские медиа и dev-зависимости
 * физически не попадают в staging, а не «отфильтровываются на выходе».
 *
 * Подпись манифеста:
 *   NABILET_UPDATE_PRIVATE_KEY     — путь к PEM-файлу с приватным ключом
 *   NABILET_UPDATE_PRIVATE_KEY_PEM — сам PEM (с \n вместо переводов строк)
 * Без ключа манифест получает пустую подпись: ручная загрузка по SHA-256
 * работает, но автоматическое обновление из ленты её отвергнет.
 */

require __DIR__ . '/../tools/lib/release-version.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** @param list<string> $args */
function nabilet_build_parse(array $args): array
{
    $options = ['version' => null, 'output-dir' => null, 'no-composer' => false, 'help' => false];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--version=')) {
            $options['version'] = substr($arg, strlen('--version='));
        } elseif (str_starts_with($arg, '--output-dir=')) {
            $options['output-dir'] = substr($arg, strlen('--output-dir='));
        } elseif ($arg === '--no-composer') {
            // Только для отладки сборки: результат НЕ является релизом.
            $options['no-composer'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } else {
            throw new RuntimeException("Неизвестный аргумент: {$arg}");
        }
    }

    return $options;
}

function nabilet_build_copy_tree(string $source, string $destination, ?callable $filter = null): void
{
    if (! is_dir($source)) {
        throw new RuntimeException("Нет входного каталога: {$source}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
        if ($relative === '' || ($filter !== null && ! $filter($relative, $item))) {
            continue;
        }

        $target = $destination . '/' . $relative;
        if ($item->isLink()) {
            throw new RuntimeException("Символические ссылки в релизе запрещены: {$relative}");
        }
        if ($item->isDir()) {
            if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
                throw new RuntimeException("Не удалось создать каталог: {$target}");
            }
        } elseif ($item->isFile()) {
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException("Не удалось создать каталог: " . dirname($target));
            }
            if (! copy($item->getPathname(), $target)) {
                throw new RuntimeException("Не удалось скопировать: {$relative}");
            }
        }
    }
}

/** @return list<string> */
function nabilet_build_files(string $root): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException("Символическая ссылка в staging: " . $item->getPathname());
        }
        if ($item->isFile()) {
            $out[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        }
    }
    sort($out, SORT_STRING);

    return $out;
}

/** @return list<string> Пакеты, которые есть только в packages-dev и не нужны на хостинге. */
function nabilet_build_dev_only(string $repo): array
{
    $raw = (string) @file_get_contents($repo . '/composer.lock');
    $lock = json_decode($raw, true);
    if (! is_array($lock)) {
        throw new RuntimeException('composer.lock: не удалось прочитать lock-файл.');
    }

    $prod = [];
    foreach (($lock['packages'] ?? []) as $package) {
        if (isset($package['name']) && is_string($package['name'])) {
            $prod[] = $package['name'];
        }
    }
    $dev = [];
    foreach (($lock['packages-dev'] ?? []) as $package) {
        $name = $package['name'] ?? null;
        if (is_string($name) && ! in_array($name, $prod, true)) {
            $dev[] = $name;
        }
    }

    return $dev;
}

/** @param list<string> $devPackages */
function nabilet_build_assert_safe(string $relative, array $devPackages): void
{
    if ($relative === '' || str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:~', $relative) === 1
        || str_contains($relative, '..') || str_contains($relative, '\\')) {
        throw new RuntimeException("Недопустимый путь в архиве: {$relative}");
    }

    if (str_starts_with($relative, 'storage/') && basename($relative) !== '.gitignore') {
        throw new RuntimeException('В архив попало рабочее хранилище: ' . $relative);
    }
    if (str_starts_with($relative, 'bootstrap/cache/') && basename($relative) !== '.gitignore') {
        throw new RuntimeException('В архив попал закешированный конфиг: ' . $relative);
    }

    $forbidden = [
        '~(^|/)\.env$~'                                            => 'секреты .env',
        '~(^|/)\.env\.(?!example$)~'                               => 'копия .env',
        // Только верхний уровень: у сторонних пакетов тоже бывают docs/tests.
        '~^(tests|tools|docs|node_modules|\.git|\.tmp-release-stage)(/|$)~' => 'инструменты разработки',
        '~^(releases|playwright-report|test-results)(/|$)~'              => 'артефакты сборки',
        '~\.(log|bak|enc|zip|sql)$~'                                => 'логи, бэкапы и дампы',
    ];

    foreach ($forbidden as $pattern => $reason) {
        if (preg_match($pattern, $relative) === 1) {
            throw new RuntimeException(sprintf('В архив попал %s: %s', $reason, $relative));
        }
    }
    foreach ($devPackages as $name) {
        if (str_starts_with($relative, 'vendor/' . $name . '/')) {
            throw new RuntimeException('В архив попала dev-зависимость: ' . $relative);
        }
    }
}

function nabilet_build_composer(string $stage): void
{
    $command = 'composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction --no-progress';
    $tmp = rtrim(sys_get_temp_dir(), '/\\');
    $prefix = $tmp . '/nabilet-composer-' . bin2hex(random_bytes(5));
    $outLog = $prefix . '.out.log';
    $errLog = $prefix . '.err.log';

    // File-backed output: blocking reads from proc_open pipes are unreliable on
    // Windows, and a silent network stall used to look like a hung builder.
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $outLog, 'w'],
        2 => ['file', $errLog, 'w'],
    ];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $stage);
    if (! is_resource($process)) {
        throw new RuntimeException('Не удалось запустить composer install.');
    }
    fclose($pipes[0]);

    $timeout = (float) (getenv('NABILET_BUILD_COMPOSER_TIMEOUT') ?: 900);
    $deadline = microtime(true) + $timeout;
    $seenOut = 0;
    $seenErr = 0;
    $capture = '';

    while (true) {
        $out = (string) @file_get_contents($outLog);
        $err = (string) @file_get_contents($errLog);
        if (strlen($out) > $seenOut) {
            $chunk = substr($out, $seenOut);
            $seenOut = strlen($out);
            $capture .= $chunk;
            fwrite(STDOUT, $chunk);
        }
        if (strlen($err) > $seenErr) {
            $chunk = substr($err, $seenErr);
            $seenErr = strlen($err);
            $capture .= $chunk;
            fwrite(STDERR, $chunk);
        }

        $status = proc_get_status($process);
        if (! (bool) $status['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            throw new RuntimeException(sprintf(
                'composer install превысил лимит %d с (журнал: %s). Проверьте доступность Packagist.',
                (int) $timeout,
                $outLog,
            ));
        }
        usleep(250000);
    }

    $capture .= (string) @file_get_contents($outLog);
    $capture .= (string) @file_get_contents($errLog);
    $code = proc_close($process);

    if ($code !== 0) {
        throw new RuntimeException("composer install завершился с кодом {$code}:\n" . $capture);
    }
    if (! is_file($stage . '/vendor/autoload.php')) {
        throw new RuntimeException('composer install не создал vendor/autoload.php.');
    }
    @unlink($outLog);
    @unlink($errLog);
}

function nabilet_build_sign(string $canonical): string
{
    $pem = '';
    $keyPath = getenv('NABILET_UPDATE_PRIVATE_KEY');
    $inline = getenv('NABILET_UPDATE_PRIVATE_KEY_PEM');

    if (is_string($keyPath) && $keyPath !== '' && is_file($keyPath)) {
        $pem = (string) file_get_contents($keyPath);
    } elseif (is_string($inline) && $inline !== '') {
        $pem = str_replace('\\n', "\n", $inline);
    }

    if (trim($pem) === '') {
        return '';
    }

    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        throw new RuntimeException('NABILET_UPDATE_PRIVATE_KEY: не удалось прочитать приватный ключ.');
    }
    $signature = '';
    if (! openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Не удалось подписать манифест релиза.');
    }

    return base64_encode($signature);
}

function nabilet_build_assert_fresh_dist(string $repo): void
{
    $index = $repo . '/dist/index.html';
    if (! is_file($index) || ! is_dir($repo . '/dist/assets')) {
        throw new RuntimeException('Нет свежей сборки: выполните npm run build перед сборкой архива.');
    }

    $builtAt = (int) filemtime($index);
    $sources = [];
    foreach (['resources', 'vite.config.ts', 'package.json', 'package-lock.json'] as $path) {
        $full = $repo . '/' . $path;
        if (is_file($full)) {
            $sources[] = $full;
        } elseif (is_dir($full)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $sources[] = $item->getPathname();
                }
            }
        }
    }

    foreach ($sources as $source) {
        if ((int) filemtime($source) > $builtAt + 2) {
            throw new RuntimeException(sprintf(
                'dist/ устарел: %s новее сборки. Выполните npm run build — архив не должен содержать прошлую сборку.',
                str_replace($repo . '/', '', $source),
            ));
        }
    }
}

// ── Точка входа ──────────────────────────────────────────────────────────────

try {
    $options = nabilet_build_parse(array_slice($argv, 1));
    if ($options['help']) {
        fwrite(STDOUT, "Usage: php scripts/build-hosting-zip.php --version=X.Y.Z [--output-dir=releases] [--no-composer]\n");
        exit(0);
    }

    $repo = dirname(__DIR__);
    $version = nabilet_release_assert($repo, $options['version'], false);
    nabilet_build_assert_fresh_dist($repo);

    $outputDir = is_string($options['output-dir']) && $options['output-dir'] !== ''
        ? $options['output-dir']
        : $repo . '/releases';
    if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
        throw new RuntimeException("Не удалось создать каталог релизов: {$outputDir}");
    }
    $outputDir = rtrim((string) realpath($outputDir), '/\\');

    $stage = rtrim(sys_get_temp_dir(), '/\\') . '/nabilet-build-' . bin2hex(random_bytes(6));
    if (! mkdir($stage, 0775, true)) {
        throw new RuntimeException('Не удалось создать staging-каталог.');
    }
    register_shutdown_function(static function () use ($stage): void {
        if (! is_dir($stage)) {
            return;
        }
        $cleanup = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($cleanup as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($stage);
    });

    foreach (['app', 'config', 'database', 'lang', 'resources', 'routes', 'dist'] as $dir) {
        nabilet_build_copy_tree($repo . '/' . $dir, $stage . '/' . $dir);
    }

    if (! is_dir($stage . '/bootstrap/cache')
        && ! mkdir($stage . '/bootstrap/cache', 0775, true)) {
        throw new RuntimeException('Не удалось создать bootstrap/cache.');
    }
    foreach (glob($repo . '/bootstrap/*.php') ?: [] as $file) {
        copy($file, $stage . '/bootstrap/' . basename($file));
    }
    file_put_contents(
        $stage . '/bootstrap/cache/.gitignore',
        "*\n!.gitignore\n",
    );

    foreach (['.env.example', 'CHANGELOG.md', 'artisan', 'composer.json', 'composer.lock'] as $file) {
        if (! is_file($repo . '/' . $file) || ! copy($repo . '/' . $file, $stage . '/' . $file)) {
            throw new RuntimeException("Нет входного файла: {$file}");
        }
    }

    if (! is_dir($stage . '/public') && ! mkdir($stage . '/public', 0775, true)) {
        throw new RuntimeException('Не удалось создать public/.');
    }
    copy($repo . '/public/index.php', $stage . '/public/index.php');

    if (! is_dir($stage . '/public_html') && ! mkdir($stage . '/public_html', 0775, true)) {
        throw new RuntimeException('Не удалось создать public_html/.');
    }
    foreach (['index.php', '.htaccess', '.user.ini'] as $file) {
        copy($repo . '/public_html/' . $file, $stage . '/public_html/' . $file);
    }
    foreach (['robots.txt', 'favicon.svg', 'installer-tailwind.js'] as $file) {
        copy($repo . '/public/' . $file, $stage . '/public_html/' . $file);
    }
    nabilet_build_copy_tree($repo . '/public/images', $stage . '/public_html/images');
    nabilet_build_copy_tree($repo . '/dist/assets', $stage . '/public_html/assets');

    foreach ([
        'storage/app/public',
        'storage/app/private',
        'storage/logs',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
    ] as $dir) {
        if (! is_dir($stage . '/' . $dir) && ! mkdir($stage . '/' . $dir, 0775, true)) {
            throw new RuntimeException("Не удалось создать {$dir}.");
        }
        file_put_contents($stage . '/' . $dir . '/.gitignore', "*\n!.gitignore\n");
    }

    if (! $options['no-composer']) {
        nabilet_build_composer($stage);
    }

    $files = nabilet_build_files($stage);
    $required = [
        'public_html/index.php',
        'public/index.php',
        'dist/index.html',
        'vendor/autoload.php',
        '.env.example',
        'composer.lock',
        'artisan',
    ];
    foreach ($required as $entry) {
        if (! in_array($entry, $files, true)) {
            throw new RuntimeException("В архиве отсутствует обязательный файл: {$entry}");
        }
    }
    $devPackages = nabilet_build_dev_only($repo);
    foreach ($files as $file) {
        nabilet_build_assert_safe($file, $devPackages);
    }

    $zipPath = $outputDir . '/nabilet-hosting-v' . $version . '-' . gmdate('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Не удалось создать ZIP: {$zipPath}");
    }
    foreach ($files as $file) {
        if (! $zip->addFile($stage . '/' . str_replace('/', DIRECTORY_SEPARATOR, $file), $file)) {
            throw new RuntimeException("Не удалось добавить в архив: {$file}");
        }
    }
    if (! $zip->close()) {
        throw new RuntimeException('Не удалось записать ZIP.');
    }

    // Проверка архива до публикации: распаковывать не нужно, достаточно
    // прочитать центральный каталог и сверить состав с безопасным списком.
    $check = new ZipArchive();
    if ($check->open($zipPath) !== true) {
        throw new RuntimeException('Собранный ZIP не открывается.');
    }
    $entries = [];
    for ($i = 0; $i < $check->numFiles; $i++) {
        $name = (string) $check->getNameIndex($i);
        nabilet_build_assert_safe($name, $devPackages);
        $entries[] = $name;
    }
    $check->close();
    foreach ($required as $entry) {
        if (! in_array($entry, $entries, true)) {
            throw new RuntimeException("После записи в архиве нет файла: {$entry}");
        }
    }
    if (in_array('index.php', $entries, true)) {
        throw new RuntimeException('Incorrect document-root layout: index.php не должен лежать в корне архива.');
    }

    $hash = hash_file('sha256', $zipPath);
    if ($hash === false) {
        throw new RuntimeException('Не удалось вычислить SHA-256.');
    }
    file_put_contents($zipPath . '.sha256', $hash . '  ' . basename($zipPath) . "\n");

    $artifact = basename($zipPath);
    $sizeBytes = (int) filesize($zipPath);
    $releasedAt = gmdate('Y-m-d\TH:i:s\Z');
    $extensions = ['curl', 'json', 'mbstring', 'openssl', 'pdo', 'zip'];
    $fromVersionsEnv = getenv('NABILET_UPDATE_FROM_VERSIONS');
    $fromVersions = is_string($fromVersionsEnv) && $fromVersionsEnv !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $fromVersionsEnv)), static fn ($v) => $v !== ''))
        : [];
    $migrations = count(glob($stage . '/database/migrations/*.php') ?: []);
    $commit = (string) (getenv('GITHUB_SHA') ?: '');
    if ($commit === '') {
        // `2>/dev/null` is not portable to cmd.exe; exec() with an exit code is.
        $gitOutput = [];
        $gitCode = 1;
        exec('git rev-parse HEAD 2>&1', $gitOutput, $gitCode);
        $commit = $gitCode === 0 ? trim(implode("\n", $gitOutput)) : '';
    }
    if ($commit === '') {
        // The source archive may be built from an exported tree without .git.
        $commit = 'unknown';
    }

    // Канонические байты обязаны побайтово совпадать с ReleaseManifest::canonical().
    $canonical = implode("\n", [
        'NABILET-UPDATE-V1',
        'schema=1',
        'version=' . $version,
        'released_at=' . $releasedAt,
        'channel=stable',
        'artifact=' . $artifact,
        'sha256=' . $hash,
        'size_bytes=' . $sizeBytes,
        'php_min=8.3.0',
        'php_max=9.0.0',
        'extensions=' . implode(',', $extensions),
        'from_versions=' . implode(',', $fromVersions),
        'migrations=' . $migrations,
    ]);
    $signature = nabilet_build_sign($canonical);

    $manifest = [
        'schema' => 1,
        'version' => $version,
        'released_at' => $releasedAt,
        'build_date' => $releasedAt,
        'commit_sha' => $commit,
        'channel' => 'stable',
        'artifact' => $artifact,
        'sha256' => $hash,
        'size_bytes' => $sizeBytes,
        'php' => [
            'min' => '8.3.0',
            'max_exclusive' => '9.0.0',
            'extensions' => $extensions,
        ],
        'from_versions' => $fromVersions,
        'migrations' => $migrations,
        'signature' => $signature,
    ];
    $manifestPath = $outputDir . '/manifest.json';
    if (file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    ) === false) {
        throw new RuntimeException('Не удалось записать manifest.json.');
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'version' => $version,
        'commit_sha' => $commit,
        'artifact' => $zipPath,
        'sha256' => $hash,
        'manifest' => $manifestPath,
        'signed' => $signature !== '',
        'entries' => count($entries),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
