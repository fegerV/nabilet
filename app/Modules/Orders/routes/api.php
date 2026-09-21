<?php

declare(strict_types=1);

use Nabilet\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/{order}', [OrderController::class, 'show']);
    Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
});
