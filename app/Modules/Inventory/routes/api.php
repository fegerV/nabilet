<?php

declare(strict_types=1);

use Nabilet\Modules\Inventory\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('inventory')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    // Admin: обновление цен рядов без регенерации геометрии (не трогает холды
    // и sold; синхронизирует открытые корзины). Статический сегмент объявлен
    // до /{inventoryItem}, иначе "sessions" проглотится wildcard-связыванием.
    Route::patch('/sessions/{sessionId}/prices', [InventoryController::class, 'updatePrices'])
        ->middleware(['auth:api', 'admin']);
    // POST-алиас: формы multipart (загрузка файлов) не умеют PATCH, а некоторые
    // прокси/клиенты режут PATCH. Семантика идентична (идемпотентная операция).
    Route::post('/sessions/{sessionId}/prices', [InventoryController::class, 'updatePrices'])
        ->middleware(['auth:api', 'admin']);
    Route::get('/sessions/{sessionId}/availability', [InventoryController::class, 'availability']);
    // `whereNumber`: неявная привязка приводит путь к int, поэтому
    // `/inventory/1abc` отдавал позицию 1. С ограничением — 404 (fail-closed).
    Route::get('/{inventoryItem}', [InventoryController::class, 'show'])->whereNumber('inventoryItem');
});
