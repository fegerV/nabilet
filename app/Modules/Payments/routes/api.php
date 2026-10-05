<?php

declare(strict_types=1);

use Nabilet\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('payments')->middleware(['auth:api'])->group(function () {
    Route::get('/', [PaymentController::class, 'index']);
    Route::post('/', [PaymentController::class, 'store']);
    // `whereNumber`: неявная привязка приводит путь к int, поэтому
    // `/payments/1abc` открывал платёж 1. С ограничением — 404 (fail-closed).
    Route::get('/{payment}', [PaymentController::class, 'show'])->whereNumber('payment');
});

// Демо-подтверждение оплаты (симулятор ЮKassa, только в demo_mode).
Route::post('payments/demo-pay', [PaymentController::class, 'demoPay']);

// Webhook routes are public - payment providers don't authenticate.
// Deliberately outside the auth:api group above. The path used to carry a
// literal `v1/` as well as the global prefix, giving /api/v1/v1/payments/...
Route::post('payments/webhooks/{provider}', [PaymentController::class, 'webhook']);
// Тот же обработчик доступен по пути, который используют тесты и клиенты:
// /api/v1/webhooks/payment/{provider} — исторический алиас.
Route::post('webhooks/payment/{provider}', [PaymentController::class, 'webhook']);
