<?php

declare(strict_types=1);

use Nabilet\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('payments')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [PaymentController::class, 'index']);
    Route::get('/{payment}', [PaymentController::class, 'show']);
});

// Webhook routes are public - payment providers don't authenticate.
// Deliberately outside the auth:sanctum group above. The path used to carry a
// literal `v1/` as well as the global prefix, giving /api/v1/v1/payments/...
Route::post('payments/webhooks/{provider}', [PaymentController::class, 'webhook']);
