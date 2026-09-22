<?php

declare(strict_types=1);

use Nabilet\Modules\Venues\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{venue}', [VenueController::class, 'show']);
    
    // Hall Schema routes
    Route::post('/{venue}/schemas', [VenueController::class, 'storeSchema']);
    Route::put('/schemas/{hallSchema}', [VenueController::class, 'updateSchema']);
    Route::delete('/schemas/{hallSchema}', [VenueController::class, 'deleteSchema']);
});
