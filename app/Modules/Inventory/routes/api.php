<?php

declare(strict_types=1);

use Nabilet\Modules\Inventory\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('inventory')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    Route::get('/{inventoryItem}', [InventoryController::class, 'show']);
    Route::get('/sessions/{sessionId}/availability', [InventoryController::class, 'availability']);
});
