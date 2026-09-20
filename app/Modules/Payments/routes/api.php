<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/payments')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [PaymentController::class, 'index']);
    Route::get('/{payment}', [PaymentController::class, 'show']);
});

// Webhook routes are public - payment providers don't authenticate
Route::post('v1/payments/webhooks/{provider}', [PaymentController::class, 'webhook']);
