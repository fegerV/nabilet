<?php

// Кто второй раз разбирает bearer-токен: снимаем стек вызовов для каждого
// запроса к `user_sessions` в пределах одного запроса.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$collecting = false;
$n = 0;

Illuminate\Support\Facades\DB::listen(function ($query) use (&$collecting, &$n): void {
    if (! $collecting || ! str_contains($query->sql, 'user_sessions')) {
        return;
    }
    $n++;
    echo "\n[{$n}] " . preg_replace('/\s+/', ' ', mb_substr($query->sql, 0, 60)) . "\n";

    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $shown = 0;
    foreach ($trace as $frame) {
        $file = str_replace('\\', '/', $frame['file'] ?? '');
        // Кадры вендора не нужны: они одинаковы для обоих вызовов, а различие
        // ищем именно в коде приложения.
        if ($file === '' || str_contains($file, 'vendor/') || str_contains($file, 'Illuminate/')) {
            continue;
        }
        echo '    ' . str_replace(str_replace('\\', '/', dirname(__DIR__)) . '/', '', $file) . ':' . ($frame['line'] ?? 0) . "\n";
        if (++$shown >= 6) {
            break;
        }
    }
});

$collecting = true;
$request = Illuminate\Http\Request::create('/api/v1/me', 'GET');
$request->headers->set('Authorization', 'Bearer ' . trim((string) getenv('AUTH_TOKEN')));
$request->headers->set('Accept', 'application/json');
$response = $kernel->handle($request);
$collecting = false;

echo "\nстатус: " . $response->getStatusCode() . ", запросов к user_sessions: {$n}\n";
