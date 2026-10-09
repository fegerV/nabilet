<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Pricing\Http\Controllers\PromoCodeController;

// Подключается централизованно из routes/api.php — префикс /api/v1 уже задан
// глобально в bootstrap/app.php. Повторять сегмент версии здесь нельзя: это
// дало бы /api/v1/v1/promo-codes. См. tools/verify-route-ownership.php.
//
// Порядок групп важен: `/promo-codes/validate` объявлен ДО параметрического
// `/promo-codes/{promoCode}`. Laravel матчит пути в порядке регистрации, и
// иначе POST /promo-codes/validate попал бы в show/обработчик кода как в
// «promoCode = validate» (для GET — 404 вместо валидатора).
Route::prefix('promo-codes')->group(function (): void {
    // Публичная проверка кода: контракт (openapi.yaml) не требует bearerAuth
    // на этой операции — покупатель проверяет код до входа, когда у него есть
    // только анонимная корзина. Операция read-only: счётчики не двигаются
    // (см. PromoCodeService::evaluateForCart).
    Route::post('/validate', [PromoCodeController::class, 'check']);

    // Остальные пять операций контракта объявлены под bearerAuth; запись
    // дополнительно под `admin` — код напрямую печатает деньги, и право
    // «придумать скидку» должно быть у организатора, а не у первой сессии.
    Route::middleware(['auth:api'])->group(function (): void {
        Route::get('/', [PromoCodeController::class, 'index']);
        Route::get('/{promoCode}', [PromoCodeController::class, 'show'])->where('promoCode', '[0-9A-Za-z]+');
    });

    Route::middleware(['auth:api', 'admin'])->group(function (): void {
        Route::post('/', [PromoCodeController::class, 'store']);
        Route::match(['put', 'patch'], '/{promoCode}', [PromoCodeController::class, 'update'])
            ->where('promoCode', '[0-9A-Za-z]+');
        Route::delete('/{promoCode}', [PromoCodeController::class, 'destroy'])
            ->where('promoCode', '[0-9A-Za-z]+');
    });
});
