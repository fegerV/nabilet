<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Checkin\Http\Controllers\Admin\CheckinDeviceController;
use Nabilet\Modules\Checkin\Http\Controllers\Admin\CheckinScanReportController;
use Nabilet\Modules\Checkin\Http\Controllers\Admin\OfflineBundleController;
use Nabilet\Modules\Checkin\Http\Controllers\CheckerController;
use Nabilet\Modules\Checkin\Http\Middleware\AuthenticateChecker;

/*
|--------------------------------------------------------------------------
| Check-in — устройства и погашение (ТЗ §31, §33, §44, §77)
|--------------------------------------------------------------------------
|
| Контракт: /api/v1/checkin/validate, /use, /sync (security: checkerAuth) и
| /api/v1/admin/checkin/devices, /admin/checkin/devices/{device},
| /admin/checkin/scans (security: bearerAuth).
|
| ДО ЭТОГО ФАЙЛА у модуля Checkin HTTP-слоя НЕ БЫЛО ВООБЩЕ: в нём лежали только
| домен (CheckinEvaluator, CheckinDevice, offline-бандлы) и провайдер-заглушка.
| Все три пути контракта отвечали 404, а `checkin_devices.device_token_hash`
| существовал без единого писателя — то есть колонка под токен, который никто не
| выдавал и не проверял. Погашение жило в `Tickets\Http\Controllers\CheckinController`
| за `['auth:api','admin']` (сессия сотрудника), и спека про устройство на входе
| оставалась невыполненной.
|
| ПОЧЕМУ `AuthenticateChecker::class`, А НЕ АЛИАС `checkerAuth`
|   `bootstrap/providers.php` намеренно не бутсит модульные провайдеры (см.
|   предупреждение в его шапке), а алиас middleware регистрируется только из
|   провайдера модуля. Ссылка на незарегистрированный алиас дала бы 500 на
|   первом же запросе, поэтому класс указан прямо.
|
| ПОРЯДОК MIDDLEWARE ВАЖЕН
|   `AuthenticateChecker` идёт ПЕРЕД `throttle:checkin-scan`: лимитер берёт
|   идентификатор устройства из атрибута, который middleware выставляет
|   (`AuthenticateChecker::ATTRIBUTE`). В обратном порядке лимит считался бы по
|   IP — а у входа меняющийся мобильный адрес, и лимит не работал бы вовсе.
|
| `idempotent` НА `use` НЕ ВИСИТ, И ВОТ ПОЧЕМУ
|   Идемпотентность задаёт `client_scan_id` устройства: он переживает перезапуск
|   приложения, в отличие от ключа в заголовке. Уникальный индекс
|   `uq_ticket_scans_client (device_id, client_scan_id)` — это уже база, а не
|   договорённость между процессами. Заголовок `Idempotency-Key` остаётся
|   необязательным следом контракта, но истиной служит именно client_scan_id.
*/

/*
 * Устройство на входе: идентификация по bearer-токену устройства.
 */
Route::prefix('checkin')->middleware(AuthenticateChecker::class)->group(function (): void {
    Route::post('validate', [CheckerController::class, 'validate'])
        ->middleware('throttle:checkin-scan');

    // `client_scan_id` в теле делает погашение идемпотентным; throttle тот же,
    // что и у validate: у устройства одна корзина, а не один IP.
    Route::post('use', [CheckerController::class, 'use'])
        ->middleware('throttle:checkin-scan');

    // Сверка офлайн-сканов: до 5000 строк за запрос, поэтому лимит свой —
    // пакетная выгрузка не похожа на темп нажатий у двери.
    Route::post('sync', [CheckerController::class, 'sync'])
        ->middleware('throttle:checkin-scan');
});

/*
 * Админская часть: выдавать и отзывать устройства, читать журнал входов.
 *
 * `auth:api` + `admin` — как у остального CRUD админки. `whereNumber` здесь
 * намеренно НЕТ: идентификатор устройства принимается и как число, и как
 * `public_id` (см. `CheckinDeviceController::find()`), а неявная привязка
 * привела бы путь к int и открыла бы чужое устройство по `/1abc`.
 */
Route::prefix('admin/checkin')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('devices', [CheckinDeviceController::class, 'index']);
    Route::post('devices', [CheckinDeviceController::class, 'store']);
    Route::patch('devices/{device}', [CheckinDeviceController::class, 'update']);

    Route::get('scans', [CheckinScanReportController::class, 'index']);
});

/*
 * Offline-бандлы (ы43/ы44; контракт openapi: GET/POST
 * /api/v1/checkin-devices/{device}/bundles, GET /api/v1/offline-bundles/{bundle}).
 *
 * Выдача и список — админские: бандл даёт устройству право пускать людей
 * офлайн, раздавать его может только сотрудник. Один бандл читает либо
 * владеющее устройство (nbck_-токен), либо сотрудник — идентификация выбирается
 * в контроллере по префиксу токена (см. OfflineBundleController::show()).
 */
Route::prefix('checkin-devices')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('{device}/bundles', [OfflineBundleController::class, 'index']);
    Route::post('{device}/bundles', [OfflineBundleController::class, 'issue']);
});

Route::get('offline-bundles/{bundle}', [OfflineBundleController::class, 'show']);
