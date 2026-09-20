<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/inventory')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    Route::get('/{inventoryItem}', [InventoryController::class, 'show']);
    Route::get('/sessions/{sessionId}/availability', [InventoryController::class, 'availability']);
});
