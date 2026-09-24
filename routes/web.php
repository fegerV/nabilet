<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Web routes: магазин (storefront) — собранная Vue-витрина из dist/.
 *
 * Главная страница отдаёт собранный SPA (dist/index.html), а хеш-роутинг
 * витрины работает без серверной части (createWebHashHistory).
 *
 * Ассеты витрины (dist/assets) отдаются из dist как статические файлы.
 */

Route::get('/', function () {
    $indexFile = __DIR__ . '/../dist/index.html';

    if (!file_exists($indexFile)) {
        return response()->json([
            'error' => [
                'code' => 'STORE_FRONT_NOT_BUILT',
                'message' => 'Витрина не собрана. Запустите `npm run build` (vite.config.ts → dist/).',
            ],
        ], 503);
    }

    return response(file_get_contents($indexFile))
        ->header('Content-Type', 'text/html; charset=utf-8');
});

// Ассеты витрины: /assets/<file> → dist/assets/<file>
Route::get('/assets/{path}', function (Request $request, string $path) {
    $file = realpath(__DIR__ . '/../dist/assets/' . $path);

    if (!$file || !str_starts_with($file, realpath(__DIR__ . '/../dist/'))) {
        return response()->json(['error' => ['code' => 'NOT_FOUND']], 404);
    }

    if (!file_exists($file)) {
        return response()->json(['error' => ['code' => 'NOT_FOUND']], 404);
    }

    return response(file_get_contents($file))
        ->header('Content-Type', mime_type_for($file));
});

if (!function_exists('mime_type_for')) {
    function mime_type_for(string $path): string
    {
        $dot = strrpos($path, '.');
        $ext = $dot === null ? '' : strtolower(substr($path, $dot + 1));

        switch ($ext) {
            case 'js':
                return 'application/javascript; charset=utf-8';
            case 'css':
                return 'text/css; charset=utf-8';
            case 'json':
                return 'application/json; charset=utf-8';
            case 'svg':
                return 'image/svg+xml';
            case 'png':
                return 'image/png';
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'woff2':
                return 'font/woff2';
            case 'woff':
                return 'font/woff';
            case 'ttf':
                return 'font/ttf';
            case 'mp4':
                return 'video/mp4';
            case 'ico':
                return 'image/x-icon';
            default:
                return 'application/octet-stream';
        }
    }
}

/*
 * SEO-страницы мероприятий (модуль Events): /event/{slug}/{publicId}.
 * Требуются отдельно — провайдеры модулей не бутятся (см. bootstrap/providers.php).
 */
if (file_exists(__DIR__ . '/../app/Modules/Events/routes/web.php')) {
    require __DIR__ . '/../app/Modules/Events/routes/web.php';
}

/*
 * Sitemap routes are handled by the SEO module via api.php.
 */