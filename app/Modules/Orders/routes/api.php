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
    // Возврат денег по заказу (ТЗ §85). Диалог отмены в админке обещал возврат
    // по оплаченному заказу, но выполнять его было нечем — `RefundService` не
    // вызывался ни одним маршрутом.
    Route::post('/{order}/refund', [OrderController::class, 'refund'])->middleware('admin')->whereNumber('order');
});

/*
 * Самопомощь покупателя: повторная отправка письма с билетом.
 *
 * НАМЕРЕННО ВНЕ `auth:api` — по той же причине, что `POST /payments` в модуле
 * Payments: витрина гостевая, корзина ключуется `X-Cart-Token`, у заказа
 * `user_id = NULL`. Под `auth:api` покупатель получал бы 401 и исправить
 * опечатку в адресе не мог бы вовсе — а письмо с билетом уходит именно на
 * `orders.customer_email`, и одна неверная буква означала потерю билета навсегда.
 *
 * Право даёт `OrderController::holdsRequestAccess()` (сотрудник, владелец по
 * `user_id` либо гость с токеном корзины заказа). Чужой заказ — 404, не 403.
 * Путь назван по ресурсу покупателя (`GET /my-tickets` в модуле Tickets), хотя
 * живёт здесь: заказ и постановка письма в очередь — домен Orders, а модуль
 * Tickets намеренно не зависит от Notifications.
 *
 * `throttle:ticket-resend` — эндпоинт шлёт письмо на адрес из тела запроса,
 * поэтому лимит обязателен, иначе он становится релеем для спама.
 */
Route::post('/my-tickets/resend', [OrderController::class, 'resendTicket'])
    ->middleware('throttle:ticket-resend');
