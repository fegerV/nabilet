<?php

declare(strict_types=1);

use Illuminate\Http\Request;
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
    // Путь к оболочке читается из того же ключа, что и в
    // `EventPageController::spaShellPath()`. Вопрос «собрана ли витрина» обязан
    // иметь один ответ: раньше здесь стоял жёсткий `__DIR__ . '/../dist/index.html'`,
    // и вторая копия решения расходилась с первой при любом изменении. Заодно
    // тесты получили возможность подменить путь вместо удаления реальной сборки.
    $indexFile = (string) config('nabilet.storefront.shell_path', base_path('dist/index.html'));

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
 * Посадочные страницы города и площадки (модуль Seo, аудит P-12):
 * /city/{slug}, /venue/{slug}.
 *
 * Подключаются ЗДЕСЬ — до `Route::fallback()` ниже. `fallback()` перехватывает
 * всё, что не совпало ни с одним зарегистрированным маршрутом, поэтому маршрут,
 * объявленный после него, не получит запрос никогда.
 */
if (file_exists(__DIR__ . '/../app/Modules/Seo/routes/web.php')) {
    require __DIR__ . '/../app/Modules/Seo/routes/web.php';
}

/*
 * Редакционные страницы CMS (модуль Content, стратегия SEO, B-2): /page/{slug}.
 *
 * Подключаются ЗДЕСЬ — до `Route::fallback()` ниже и после посадочных
 * страниц Seo. `fallback()` перехватывает всё, что не совпало ни с одним
 * зарегистрированным маршрутом, поэтому маршрут, объявленный после него, не
 * получит запрос никогда. Порядок относительно группы Seo значения не имеет —
 * префиксы `/page/`, `/venue/`, `/city/`, `/category/`, `/organizer/`,
 * `/artist/` не пересекаются.
 */
if (file_exists(__DIR__ . '/../app/Modules/Content/routes/web.php')) {
    require __DIR__ . '/../app/Modules/Content/routes/web.php';
}

/*
 * Installer (мастер установки, WordPress-стиль): /install
 * Грузится из web-роутов (а не api), чтобы URL был чистым — /install без
 * api/v1-префикса, и маршрут попадал в web-группу (сессии,共享错误), а не в api.
 */
if (file_exists(__DIR__ . '/../app/Modules/Installer/routes/web.php')) {
    require __DIR__ . '/../app/Modules/Installer/routes/web.php';
}

/*
 * Sitemap routes are handled by the SEO module via api.php.
 */

/*
 * Брендированная 404 для любого несуществующего пути (SEO-аудит P-16).
 *
 * `Route::fallback()`, А НЕ `Route::any('/{any}')`. Это принципиально:
 * `withRouting()` регистрирует web-маршруты РАНЬШЕ api-маршрутов, поэтому
 * catch-all с маской `{any}` перехватил бы `/api/v1/...` — то есть весь API
 * начал бы отвечать HTML-страницей 404. `fallback()` роутер проверяет ТОЛЬКО
 * после того, как не совпал ни один обычный маршрут, независимо от порядка
 * регистрации: он и создан для этого случая.
 *
 * Почему вообще нужно: без него неизвестный путь отдавал ответ фреймворка
 * (JSON или голый HTML без навигации). Человек, пришедший по устаревшей ссылке,
 * оставался в тупике, а робот не получал ни одной ссылки, по которой можно
 * пройти дальше. См. `Nabilet\Core\Http\NotFoundPage` — там же объяснение,
 * почему страница не зависит от сборки витрины и от внешних ресурсов.
 *
 * `follow`, а не `nofollow`: единственный осмысленный сигнал отсюда ведёт в
 * каталог, и передавать по нему вес полезно.
 *
 * ЗАПРОСЫ К API И ОЖИДАЮЩИЕ JSON ОТДАЮТСЯ КАК ПРЕЖДЕ — через `abort(404)`.
 * Это не «частный случай», а восстановление контракта: до появления fallback
 * неизвестный путь внутри API обрабатывался исключением и рендерился
 * `ApiExceptionRenderer` в конверт §66. HTML-страница в ответ на
 * `Accept: application/json` — ошибка контракта, которую замечает не каждый
 * клиент, поэтому для API путь остаётся ровно тем же, что и был.
 */
Route::fallback(function (Request $request) {
    if ($request->is('api/*') || $request->expectsJson()) {
        abort(404);
    }

    return \Nabilet\Core\Http\NotFoundPage::response($request->getPathInfo());
});
