<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Storefront\Http\Controllers\StorefrontController;

/*
 * Конструктор витрины.
 *
 * Файл подключается из routes/api.php, поэтому префикс /api/v1 уже установлен —
 * повторять версию здесь нельзя (так раньше получился мёртвый /api/v1/v1/…).
 *
 * Публичный конфиг читается гостем до авторизации: это то, из чего
 * собирается афиша. Всё, что меняет данные, — под auth:api + admin.
 */
Route::get('/storefront', [StorefrontController::class, 'show']);

Route::middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('/admin/storefront/schema', [StorefrontController::class, 'schema']);
    Route::get('/admin/storefront', [StorefrontController::class, 'edit']);
    Route::put('/admin/storefront', [StorefrontController::class, 'update']);
    Route::post('/admin/storefront/reset', [StorefrontController::class, 'reset']);
});
