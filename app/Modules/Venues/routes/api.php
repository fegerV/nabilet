<?php

declare(strict_types=1);

use Nabilet\Modules\Venues\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::post('/', [VenueController::class, 'store'])->middleware(['auth:sanctum', 'admin']);
    Route::get('/{venue}', [VenueController::class, 'show']);
    Route::patch('/{venue}', [VenueController::class, 'update'])->middleware(['auth:sanctum', 'admin']);
    Route::delete('/{venue}', [VenueController::class, 'destroy'])->middleware(['auth:sanctum', 'admin']);
    
    // Hall Schema routes
    Route::post('/{venue}/schemas', [VenueController::class, 'storeSchema'])->middleware(['auth:sanctum', 'admin']);
    Route::put('/schemas/{hallSchema}', [VenueController::class, 'updateSchema'])->middleware(['auth:sanctum', 'admin']);
    Route::delete('/schemas/{hallSchema}', [VenueController::class, 'deleteSchema'])->middleware(['auth:sanctum', 'admin']);
});
