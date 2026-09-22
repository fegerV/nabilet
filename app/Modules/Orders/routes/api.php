<?php

declare(strict_types=1);

use Nabilet\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/{order}', [OrderController::class, 'show']);
    Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
});
