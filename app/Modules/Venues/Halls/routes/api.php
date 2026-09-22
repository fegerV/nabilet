<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Venues\Halls\Http\Controllers\HallController;

/*
 * Halls Module API Routes
 *
 * Mounted at /api/v1 by bootstrap/app.php (apiPrefix), so nothing here repeats a
 * version segment. The wrapper used to carry Route::prefix('api/v1'), which
 * produced /api/v1/api/v1/halls — every route below was unreachable at its
 * declared path. The wrapper was redundant once the prefix was dropped, so it is
 * gone rather than left as an empty group.
 *
 * OPEN (docs/REVIEW-spec-bundle.md): the spec places these under
 * /api/v1/admin/... (openapi.yaml: /api/v1/admin/venues/{venue}/halls,
 * /api/v1/admin/halls/{hall}). Adding that segment is a public->admin visibility
 * change, so it is recorded as a decision, not applied here.
 */

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
