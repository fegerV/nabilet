<?php

// Счётчик запросов: сколько SQL уходит на ключевые страницы витрины.
// Запускается через ядро приложения, поэтому запросы те же, что в бою.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$uris = array_slice($argv, 1) ?: ['/api/v1/storefront', '/api/v1/events?per_page=12'];

// Слушатель регистрируется один раз: `DB::listen` не умеет отписываться, и
// при регистрации на каждый URI запросы считались бы всеми слушателями сразу
// (второй запрос показывал ровно x2 — это была ошибка инструмента, не N+1).
$log = [];
$collecting = false;
Illuminate\Support\Facades\DB::listen(function ($query) use (&$log, &$collecting): void {
    if (! $collecting) {
        return;
    }
    // Нормализуем литералы, чтобы увидеть повторы одной и той же формы.
    $log[] = preg_replace('/\?|\d+/', '?', $query->sql);
});

foreach ($uris as $uri) {
    $log = [];
    $collecting = true;

    $started = microtime(true);
    $request = Illuminate\Http\Request::create($uri, 'GET');
    // Токен — из окружения: админские списки без него отдают 401 и ничего не
    // показывают про запросы.
    $token = getenv('AUTH_TOKEN');
    if ($token) {
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $request->headers->set('Accept', 'application/json');
    }
    $response = $kernel->handle($request);
    $elapsed = (microtime(true) - $started) * 1000;
    $collecting = false;

    $count = count($log);
    $top = array_count_values($log);
    arsort($top);

    echo "\n=== {$uri}\n";
    echo '  статус ' . $response->getStatusCode() . ' · ' . round($elapsed) . " мс · запросов: {$count}\n";
    $i = 0;
    foreach ($top as $sql => $n) {
        if ($i++ >= 6) {
            break;
        }
        $short = mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 110);
        echo "  x{$n}  {$short}\n";
    }
}
