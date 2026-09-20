<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/payments')->group(function () {
    Route::get('/', [PaymentController::class, 'index']);
    Route::get('/{payment}', [PaymentController::class, 'show']);
    Route::post('/webhooks/{provider}', [PaymentController::class, 'webhook']);
});
