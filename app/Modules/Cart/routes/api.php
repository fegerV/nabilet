<?php

declare(strict_types=1);

use Nabilet\Modules\Cart\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::delete('/items/{itemId}', [CartController::class, 'removeItem']);
    Route::post('/checkout', [CartController::class, 'checkout']);
    // B5: продление серверного холда. Гостевой токен (X-Cart-Token) обязателен —
    // без него сервер не найдёт корзину покупателя и вернёт CART_EXPIRED.
    Route::post('/extend', [CartController::class, 'extend']);
});
