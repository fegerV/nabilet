<?php

declare(strict_types=1);

use Nabilet\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// Весь префикс закрыт `auth:api`. Раньше авторизация стояла только на отмене,
// поэтому анонимный запрос мог выгрузить все заказы (`GET /orders`),
// прочитать любой заказ вместе с позициями, платежами, билетами и PII
// покупателя (`GET /orders/{order}`) и создать заказ от чужого имени
// (`POST /orders`). Спека требует bearerAuth на всех трёх
// (openapi.yaml: POST /api/v1/orders, GET /api/v1/orders/{order}).
//
// Разграничение «все / только свои» — в контроллере: сотрудник видит всё,
// обычный пользователь принудительно ограничен своим `user_id`, даже если
// подставил чужой в query. Это ровно то, что спека разделяет как
// GET /admin/orders и GET /me/orders.
Route::prefix('orders')->middleware(['auth:api'])->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    // `whereNumber`: неявная привязка приводит путь к int, поэтому `/orders/1abc`
    // открывал заказ 1. С ограничением — 404 (fail-closed).
    Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order');
    Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->middleware('admin')->whereNumber('order');
});
