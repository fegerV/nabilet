<?php

declare(strict_types=1);

use Nabilet\Modules\Payments\Http\Controllers\PaymentController;
use Nabilet\Modules\Payments\Http\Controllers\PaymentSettingsController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// `GET` — под `auth:api`: это чтение, и владелец проверяется в контроллере.
//
// `POST /` намеренно ВНЕ `auth:api`. Витрина — гостевой сценарий: корзина
// ключуется `X-Cart-Token`, покупатель не логинится, и у заказа `user_id = NULL`.
// Под `auth:api` аноним получал 401, а залогиненный — 404, поэтому оплатить
// гостевой заказ было нельзя вообще. Авторизацию делает
// `PaymentController::mayPay()`: сотрудник, владелец по `user_id` либо гость с
// токеном той корзины, из которой создан заказ. Чужой заказ — 404.
Route::prefix('payments')->group(function () {
    Route::get('/', [PaymentController::class, 'index'])->middleware('auth:api');
    Route::post('/', [PaymentController::class, 'store']);
    // `whereNumber`: неявная привязка приводит путь к int, поэтому
    // `/payments/1abc` открывал платёж 1. С ограничением — 404 (fail-closed).
    Route::get('/{payment}', [PaymentController::class, 'show'])->middleware('auth:api')->whereNumber('payment');

    // Возврат денег — финансовая операция, поэтому `admin`. До появления этого
    // маршрута `RefundService` не вызывался ниоткуда: вернуть деньги покупателю
    // было физически невозможно (ТЗ §85).
    Route::post('/{payment}/refund', [PaymentController::class, 'refund'])
        ->middleware(['auth:api', 'admin'])
        ->whereNumber('payment');
});

// Демо-подтверждение оплаты (симулятор ЮKassa, только в demo_mode).
Route::post('payments/demo-pay', [PaymentController::class, 'demoPay']);

//
// НАСТРОЙКИ ПРИЁМА ПЛАТЕЖЕЙ В АДМИНКЕ.
//
// Ключи ЮKassa живут в `.env`, а на шаред-хостинге владелец магазина туда не
// заглянет — включить оплату можно было только через поддержку хостинга.
// Экран сохраняет shop_id/secret_key/webhook_secret/demo_mode/провайдер по
// образцу `admin/mail` (таблица `settings`, scope=`payment`); значения из БД
// перекрывают env и применяются к runtime-конфигу на каждом бутстрапе.
//
// `admin` поверх `auth:api`: здесь ключи приёма денег.
Route::prefix('admin/payments')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/settings', [PaymentSettingsController::class, 'show']);
    Route::put('/settings', [PaymentSettingsController::class, 'update']);
});

// Webhook routes are public - payment providers don't authenticate.
// Deliberately outside the auth:api group above. The path used to carry a
// literal `v1/` as well as the global prefix, giving /api/v1/v1/payments/...
Route::post('payments/webhooks/{provider}', [PaymentController::class, 'webhook']);
// Тот же обработчик доступен по пути, который используют тесты и клиенты:
// /api/v1/webhooks/payment/{provider} — исторический алиас.
Route::post('webhooks/payment/{provider}', [PaymentController::class, 'webhook']);
