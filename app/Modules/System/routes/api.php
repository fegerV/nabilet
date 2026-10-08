<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\System\Http\Controllers\HealthController;
use Nabilet\Modules\System\Http\Controllers\SystemStatusController;

/*
 * Модуль System: проверка работоспособности и админская диагностика.
 *
 * Подключается централизованно из `routes/api.php` — как и остальные модули.
 * `SystemServiceProvider` роуты НЕ грузит (см. комментарий в провайдере):
 * файл, загруженный провайдером, минует `apiPrefix: 'api/v1'` из
 * `bootstrap/app.php` и зарегистрировался бы в корне — `/health` вместо
 * `/api/v1/health`, то есть ровно мимо контракта из `openapi.yaml`.
 */

/*
 * Публичный эндпоинт состояния (ТЗ §73, спека `openapi.yaml:1292`).
 *
 * Без `auth` — и это осознанно: его опрашивает load balancer, у которого нет
 * и не может быть пользовательского токена. Поэтому в ответе нет ничего, что
 * нельзя показывать анонимному клиенту: ни версий PHP/Laravel, ни путей, ни
 * объёма диска. Подробная диагностика — ниже, за `admin`.
 */
Route::get('/health', [HealthController::class, 'show']);

/*
 * Админская диагностика. Только `admin`/`manager` (EnsureAdminRole).
 *
 * Здесь и только здесь живут сведения, раскрытие которых анонимному клиенту
 * было бы разведкой перед атакой: точные версии PHP и Laravel, геометрия
 * диска, содержимое логов.
 */
Route::prefix('admin/system')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/status', [SystemStatusController::class, 'status']);
    Route::get('/logs', [SystemStatusController::class, 'viewLogs']);
    Route::post('/cache/clear', [SystemStatusController::class, 'clearCache']);
});
