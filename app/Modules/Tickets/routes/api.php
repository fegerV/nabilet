<?php

declare(strict_types=1);

use Nabilet\Modules\Tickets\Http\Controllers\CheckinController;
use Nabilet\Modules\Tickets\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// Гостевой «мои билеты»: билеты покупателя по X-Cart-Token (контракт D5).
// Без auth:api — гостевая витрина не имеет аккаунта, а order.user_id = NULL.
// Скоупровано по токену корзины, поэтому чужие билеты не отдаются. Вынесен из
// auth:api-группы ниже: идентификация идёт по гостевому токену, а не по Bearer.
Route::get('/my-tickets', [TicketController::class, 'mine']);

// Раньше ВСЕ маршруты модуля были открыты, включая `GET /tickets/{ticket}/qr`,
// который отдаёт `qr_payload` — подписанный токен входа. Анонимный запрос мог
// перебрать билеты и собрать QR-ы (спека требует bearerAuth на каждом из
// четырёх read-маршрутов: openapi.yaml: GET /api/v1/tickets,
// /tickets/{ticket}, /tickets/{ticket}/qr, /tickets/{ticket}/pdf).
//
// Чекин: спека помечает его схемой `checkerAuth` (bearer с device-token),
// но такого guard'а в приложении нет — ни проверки `device_token_hash`, ни
// выдачи токена устройству. До его появления чекин закрыт сессией сотрудника:
// это строго лучше прежнего открытого доступа, при котором любой желающий мог
// погасить чужой билет через POST /tickets/checkin/scan.
Route::prefix('tickets')->group(function () {
    Route::middleware(['auth:api', 'admin'])->group(function () {
        Route::post('/checkin/scan', [CheckinController::class, 'scan']);
        Route::post('/checkin/verify', [CheckinController::class, 'verify']);
    });

    Route::middleware(['auth:api'])->group(function () {
        Route::get('/', [TicketController::class, 'index']);
        // `whereNumber`: неявная привязка приводит путь к int, поэтому
        // `/tickets/1abc` открывал билет 1 (включая `/qr` с его подписанным
        // токеном). С ограничением — 404.
        Route::get('/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket');
        Route::get('/{ticket}/qr', [TicketController::class, 'qrCode'])->whereNumber('ticket');
        Route::get('/{ticket}/history', [TicketController::class, 'history'])->whereNumber('ticket');
    });
});
