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

// Контрактный путь того же экшена: openapi объявляет refunds под
// `/api/v1/admin/orders/{order}/refund`, где OrderIdPath — строка, поэтому
// без whereNumber: резолв public_id/числа живёт в самом экшене. Два адреса —
// один метод; расхождение контракта и кода было находкой аудита.
Route::post('admin/orders/{order}/refund', [OrderController::class, 'refund'])
    ->middleware(['auth:api', 'admin']);

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

/*
 * `GET /api/v1/me/orders` — «мои заказы» личного кабинета (`listMyOrders`).
 *
 * Путь объявлен в спеке рядом с остальными `/me/*`, но живёт здесь: заказ —
 * домен Orders, и скоупинг по владельцу (`OrderController::mine`) обязан быть
 * рядом с `GET /orders`, иначе правило «не-сотрудник видит только своё»
 * пришлось бы держать в двух местах и они бы разошлись.
 *
 * `auth:api` обязателен: без владельца у запроса нет «моих» заказов вообще.
 * Отдельный метод, а не переиспользование `index()`: у сотрудника `index()`
 * НЕ ограничивает выборку владельцем (там фильтр `user_id` — из query), и в
 * кабинете он увидел бы заказы всей системы.
 */
Route::get('/me/orders', [OrderController::class, 'mine'])->middleware('auth:api');
