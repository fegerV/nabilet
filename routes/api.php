<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

/*
 * Liveness-проба: отвечает «процесс жив и обслуживает запросы».
 *
 * ВНИМАНИЕ: этот маршрут НИЧЕГО не проверяет и отвечает 200 всегда — в том
 * числе при недоступной базе. Как проверка работоспособности он непригоден, и
 * раньше именно в этой роли он и использовался (`docs/SERVER-HEALTH.md`,
 * отчёты по живой среде): монитор показывал зелёный свет на мёртвой БД.
 *
 * Состояние зависимостей проверяет `GET /api/v1/health`
 * (`app/Modules/System/routes/api.php`): он отвечает 503 при отказе критичной
 * зависимости. Оставляем `/ping` как дешёвую liveness-пробу для
 * существующего smoke-теста и не меняем его семантику, но не путать одно с
 * другим: монитор должен смотреть на `/health`.
 */
Route::get('/ping', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));

/*
|--------------------------------------------------------------------------
| Module Routes — Safe Route Loader
|--------------------------------------------------------------------------
|
| All modules are now enabled. Missing classes have been created as
| aliases/extensions to resolve the DI chain.
*/

if (file_exists(__DIR__ . '/../app/Modules/Auth/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Auth/routes/api.php';
}

$moduleRoutes = [
    __DIR__ . '/../app/Modules/Events/routes/api.php',
    __DIR__ . '/../app/Modules/Sessions/routes/api.php',
    __DIR__ . '/../app/Modules/Venues/routes/api.php',
    __DIR__ . '/../app/Modules/Inventory/routes/api.php',
    __DIR__ . '/../app/Modules/Cart/routes/api.php',
    __DIR__ . '/../app/Modules/Orders/routes/api.php',
    __DIR__ . '/../app/Modules/Payments/routes/api.php',
    __DIR__ . '/../app/Modules/Tickets/routes/api.php',
    __DIR__ . '/../app/Modules/Webhooks/routes/api.php',
    // Редактирование шаблонов транзакционных писем (админка).
    __DIR__ . '/../app/Modules/Notifications/routes/api.php',
    // Медиа: список, загрузка, метаданные, удаление (контракт: /api/v1/media).
    // Файл маршрутов существовал не всегда: провайдер модуля пытался загрузить
    // его из `boot()`, но каталога `routes/` не было, а сам провайдер не был
    // зарегистрирован — поэтому вызов не выполнялся и ошибка была не видна.
    __DIR__ . '/../app/Modules/Media/routes/api.php',
    __DIR__ . '/../app/Modules/Core/Users/routes/api.php',
    __DIR__ . '/../app/Modules/Core/Organizations/routes/api.php',
    __DIR__ . '/../app/Modules/Venues/Halls/routes/api.php',
    __DIR__ . '/../app/Modules/Seo/routes/api.php',
    // Конструктор витрины: публичный конфиг + админские настройки.
    __DIR__ . '/../app/Modules/Storefront/routes/api.php',
    // Аналитика: публичный конфиг счётчика + админские настройки Метрики.
    // Файл существовал, но не был подключён — раздел «Интеграции» в админке
    // упирался в 404 для обоих эндпоинтов.
    __DIR__ . '/../app/Modules/Analytics/routes/api.php',
    // Состояние системы: публичный GET /health (контракт openapi.yaml:1292)
    // и админская диагностика. Публичный эндпоинт обязан быть подключён:
    // без него монитор судит о живости по /ping, который всегда отвечает 200.
    __DIR__ . '/../app/Modules/System/routes/api.php',
];

foreach ($moduleRoutes as $routeFile) {
    if (file_exists($routeFile)) {
        require $routeFile;
    }
}