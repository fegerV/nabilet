<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Venues\Halls\Http\Controllers\HallController;

/*
 * Halls Module API Routes
 */

Route::prefix('api/v1')->group(function () {
    // Public hall routes
    Route::get('/venues/{venueId}/halls', [HallController::class, 'index'])->name('halls.index');
    Route::get('/halls/{publicId}', [HallController::class, 'show'])->name('halls.show');

    // Protected hall management routes
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::post('/halls', [HallController::class, 'store'])->name('halls.store');
        Route::put('/halls/{publicId}', [HallController::class, 'update'])->name('halls.update');
        Route::delete('/halls/{publicId}', [HallController::class, 'destroy'])->name('halls.destroy');

        // Schema version management
        Route::get('/halls/{publicId}/schema-versions', [HallController::class, 'getSchemaVersions'])->name('halls.schema-versions.index');
        Route::post('/halls/{publicId}/schema-versions/draft', [HallController::class, 'createSchemaDraft'])->name('halls.schema-versions.draft');
        Route::post('/schema-versions/{versionId}/publish', [HallController::class, 'publishSchemaVersion'])->name('halls.schema-versions.publish');
    });
});
