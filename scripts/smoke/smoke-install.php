<?php

declare(strict_types=1);

/**
 * Smoke-прогон установки v1.2.0 на чистом стенде (.tmp-release-stage/site).
 * Предусловия:
 *   1. php .tmp-release-stage/db-setup-smoke.php          (чистая БД + юзер)
 *   2. в .tmp-release-stage/site нет .env и storage/install.lock
 *   3. php -S 127.0.0.1:8088 router-smoke.php  (из каталога site)
 *
 * Мастер-копия живёт в scripts/smoke/: сборщик зипа стирает
 * .tmp-release-stage целиком — после build-hosting-zip.ps1 копируйте
 * файлы обратно (см. docs/deploy-timeweb.md, раздел e2e).
 */

$base = 'http://127.0.0.1:8088';
$siteDir = __DIR__ . '/site';

$checks = [];
$fail = 0;

function check(string $name, bool $ok, string $note = ''): void
{
    global $checks, $fail;
    $checks[] = [$name, $ok, $note];
    if (! $ok) {
        $fail++;
    }
    printf("  [%s] %s%s\n", $ok ? 'OK  ' : 'FAIL', $name, $note !== '' ? ' — ' . $note : '');
}

/** @return array{0:int,1:string,2:string} */
function http(string $method, string $url, array $post = [], array $headers = []): array
{
    $ch = curl_init($url);
    $allHeaders = $headers;
    if ($post !== []) {
        $allHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $allHeaders,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = substr($raw, 0, $headSize);
    $body = substr($raw, $headSize);

    return [$status, $body, $head];
}

// ── ДО установки ─────────────────────────────────────────────────────────────

[$status, $body, $head] = http('GET', $base . '/install');
check('GET /install -> 200 HTML', $status === 200 && (str_contains($body, '<!DOCTYPE') || str_contains($body, '<html')), 'status=' . $status);

check('мастер самодостаточен: installer-tailwind.js в разметке', str_contains($body, '/installer-tailwind.js'));
check('нет внешних CDN (tailwindcss.com / googleapis)', ! str_contains($body, 'cdn.tailwindcss.com') && ! str_contains($body, 'googleapis'));

[$status, $body, $head] = http('GET', $base . '/installer-tailwind.js');
check('GET /installer-tailwind.js -> 200', $status === 200, 'status=' . $status . ', bytes=' . strlen($body));

[$status, $body, $head] = http('GET', $base . '/.env.example');
check('dotfile недоступен: /.env.example -> 403', $status === 403, 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/vendor/autoload.php');
check('системный каталог недоступен: /vendor/... -> 403', $status === 403, 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/config/nabilet.php');
check('системный каталог недоступен: /config/... -> 403', $status === 403, 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/robots.txt');
check('GET /robots.txt -> 200', $status === 200, 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/storage/definitely-missing.jpg');
check('несуществующий /storage/... уходит во фронт-контроллер (не 200 статики)', $status !== 200, 'status=' . $status);

// test-database: позитив и негатив
$creds = [
    'db_host' => '127.0.0.1',
    'db_port' => '3307',
    'db_database' => 'install_smoke',
    'db_username' => 'installer',
    'db_password' => 'installerpass',
];

[$status, $body, $head] = http('POST', $base . '/install/test-database', $creds);
check('test-database с верными кредами -> success', $status === 200 && str_contains($body, '"success":true'), 'status=' . $status);

$bad = $creds;
$bad['db_password'] = 'wrongpass';
[$status, $body, $head] = http('POST', $base . '/install/test-database', $bad);
check('test-database с неверным паролем -> 422 DB_CONNECTION_FAILED', $status === 422 && str_contains($body, 'DB_CONNECTION_FAILED'), 'status=' . $status);

// ── Журнал инсталлера в визарде ──────────────────────────────────────────────

[$status, $body, $head] = http('GET', $base . '/install/log', [], ['Accept: application/json']);
$decoded = json_decode((string) $body, true) ?: [];
check('GET /install/log до установки -> 200 и массив entries', $status === 200 && isset($decoded['entries']), 'status=' . $status);

// Негативная установка: неверный пароль БД → INSTALL_FAILED, причина в журнале.
$payload = $creds + [
    'app_name' => 'Nabilet Smoke',
    'app_url' => 'https://daragent.ru',
    'admin_email' => 'admin@smoke.local',
    'admin_password' => 'adminpass123',
    'mail_host' => '',
    'mail_from_address' => 'smoke@smoke.local',
];
$badPayload = $payload;
$badPayload['db_password'] = 'wrongpass';

sleep(61); // чистое окно throttle:5,1 для фазы POST /install

[$status, $body, $head] = http('POST', $base . '/install', $badPayload);
check('POST /install с неверными кредами -> 500 INSTALL_FAILED', $status === 500 && str_contains($body, 'INSTALL_FAILED'), 'status=' . $status);

sleep(61); // снова чистое окно троттлинга перед следующими POST /install

[$status, $body, $head] = http('GET', $base . '/install/log', [], ['Accept: application/json']);
$decoded = json_decode((string) $body, true) ?: [];
$hasSqlstate = false;
$hasCritical = false;
foreach (($decoded['entries'] ?? []) as $e) {
    $blob = (string) ($e['message'] ?? '') . (string) ($e['exception'] ?? '');
    if (str_contains($blob, 'SQLSTATE')) {
        $hasSqlstate = true;
    }
    if (str_contains($blob, 'Критическая ошибка установки')) {
        $hasCritical = true;
    }
}
check(
    'GET /install/log содержит причину провала (SQLSTATE + Критическая ошибка установки)',
    $status === 200 && $hasSqlstate && $hasCritical,
    'status=' . $status
);

// ── УСТАНОВКА ────────────────────────────────────────────────────────────────

[$status, $body, $head] = http('POST', $base . '/install', $payload);
check('POST /install -> 200 success:true', $status === 200 && str_contains($body, '"success":true'), 'status=' . $status . ($status !== 200 ? ' body=' . substr($body, 0, 300) : ''));

// v1.1.2: ответ POST /install — чистый JSON, без посторонних баннеров.
$trimmed = trim((string) $body);
check('POST /install ответ начинается с { (JSON без посторонних баннеров)', $trimmed !== '' && $trimmed[0] === '{', 'first=' . substr($trimmed, 0, 20));
json_decode($trimmed, true);
check('POST /install ответ валидный JSON', json_last_error() === JSON_ERROR_NONE, 'err=' . json_last_error_msg());

// ── ПОСЛЕ установки ──────────────────────────────────────────────────────────

$env = is_file($siteDir . '/.env') ? (string) file_get_contents($siteDir . '/.env') : '';
check('.env записан', $env !== '');
check('.env: APP_URL=https://daragent.ru', str_contains($env, 'APP_URL=https://daragent.ru'));
check('.env: DB_HOST=127.0.0.1 / DB_PORT=3307', str_contains($env, 'DB_HOST=127.0.0.1') && str_contains($env, 'DB_PORT=3307'));
check('.env: DB_CHARSET/DB_COLLATION заполнены', str_contains($env, 'DB_CHARSET=utf8mb4') && str_contains($env, 'DB_COLLATION=utf8mb4_unicode_ci'));
check('.env: APP_KEY сгенерирован', (bool) preg_match('/^APP_KEY=base64:.+/m', $env));

check('storage/install.lock существует', is_file($siteDir . '/storage/install.lock'));

// БД: таблицы и переизданный CHECK
$pdo = new PDO('mysql:host=127.0.0.1;port=3307', 'installer', 'installerpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('USE install_smoke');

$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
check('таблиц в БД > 90 (миграции дошли до конца)', $tables > 90, 'tables=' . $tables);

$checkRow = $pdo->query(
    "SELECT CHECK_CLAUSE FROM information_schema.check_constraints WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_orders_amounts'"
)->fetchColumn();
check('CHECK ck_orders_amounts переиздан (содержит bonus_amount)', is_string($checkRow) && str_contains($checkRow, 'bonus_amount'), (string) $checkRow);

$reasonRow = $pdo->query(
    "SELECT CHECK_CLAUSE FROM information_schema.check_constraints WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_bonus_transactions_reason'"
)->fetchColumn();
check('CHECK ck_bonus_transactions_reason содержит referral_*', is_string($reasonRow) && str_contains($reasonRow, 'referral_inviter'), (string) $reasonRow);

$admin = $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'admin@smoke.local'")->fetchColumn();
check('админ-аккаунт создан', (int) $admin === 1);

// HTTP после установки
[$status, $body, $head] = http('GET', $base . '/api/v1/health', [], ['Accept: application/json']);
check('/api/v1/health -> 200 и версия 1.2.0', $status === 200 && str_contains($body, '1.2.0'), 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/install');
check('GET /install (HTML) после установки -> 302 на /', $status === 302 && preg_match('/^Location: .*\//m', $head) === 1, 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/install', [], ['Accept: application/json']);
check('GET /install (JSON) после установки -> 403 ALREADY_INSTALLED', $status === 403 && str_contains($body, 'ALREADY_INSTALLED'), 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/install/log', [], ['Accept: application/json']);
check('GET /install/log после установки -> 403 (журнал закрыт)', $status === 403 && str_contains($body, 'ALREADY_INSTALLED'), 'status=' . $status);

[$status, $body, $head] = http('POST', $base . '/install', $payload, ['Accept: application/json']);
check('повторный POST /install -> 403 ALREADY_INSTALLED', $status === 403 && str_contains($body, 'ALREADY_INSTALLED'), 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/');
check('витрина GET / -> 200', $status === 200, 'status=' . $status);

// v1.2.0: history-роутинг — SPA-оболочка на белом списке путей.
//
// Каждый экран витрины — настоящий URL. Сервер обязан отдавать собранную
// оболочку (dist/index.html), иначе F5 или прямая ссылка дают 404. Статус
// проверяем у браузерного запроса (без Accept: application/json), контент —
// по признаку собранной витрины.
$spaPaths = ['/admin', '/admin/orders', '/tickets', '/checkout', '/admin/login'];
foreach ($spaPaths as $spaPath) {
    [$status, $body, $head] = http('GET', $base . $spaPath);
    check(
        'SPA-оболочка ' . $spaPath . ' -> 200 HTML (history-роутинг)',
        $status === 200 && (str_contains($body, '<div id="app">') || str_contains($body, '<!DOCTYPE')),
        'status=' . $status
    );
}

// Сброс пароля из письма: настоящий путь, токен в query.
[$status, $body, $head] = http('GET', $base . '/reset-password?token=abc&email=a%40b.test');
check(
    'SPA-оболочка /reset-password?token=… -> 200',
    $status === 200 && (str_contains($body, '<div id="app">') || str_contains($body, '<!DOCTYPE')),
    'status=' . $status
);

// Неизвестный адрес по-прежнему брендированная 404, а не оболочка.
[$status, $body, $head] = http('GET', $base . '/definitely-not-a-screen');
check('неизвестный путь -> 404 (брендированная, не оболочка)', $status === 404, 'status=' . $status);

[$status, $body, $head] = http('GET', $base . '/favicon.svg');
check('GET /favicon.svg -> 200', $status === 200, 'status=' . $status);

// Итог
$total = count($checks);
printf("\n%s — %d/%d PASS\n", $fail === 0 ? 'SMOKE OK' : 'SMOKE FAILED', $total - $fail, $total);
exit($fail === 0 ? 0 : 1);
