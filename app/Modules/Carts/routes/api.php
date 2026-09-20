<?php

declare(strict_types=1);

use App\Modules\Carts\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::delete('/items/{itemId}', [CartController::class, 'removeItem']);
    Route::post('/checkout', [CartController::class, 'checkout']);
});
