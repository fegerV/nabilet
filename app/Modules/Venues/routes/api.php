<?php

declare(strict_types=1);

use Nabilet\Modules\Venues\Http\Controllers\VenueController;
use Nabilet\Modules\Venues\Http\Controllers\VenueImportController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// No `/venues/{venue}/schemas` routes. They targeted `Nabilet\Modules\Venues\Models\HallSchema`
// against a `hall_schemas` table — neither the class nor the table exists, so all
// three endpoints were a guaranteed fatal. Hall schemas are versioned and hang off
// a hall: see `app/Modules/Venues/Halls/routes/api.php`
// (`POST /halls/{publicId}/schema-versions/draft`, `POST /schema-versions/{id}/publish`).
Route::prefix('venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::post('/', [VenueController::class, 'store'])->middleware(['auth:api', 'admin']);
    // Импорт со страницы события Яндекс.Афиши: только разбор, создание —
    // через POST /venues. ВАЖНО: до /{venue} — иначе сегмент попал бы в
    // route-model binding по public_id и вернул 404 Venue.
    Route::post('/import-afisha', [VenueImportController::class, 'parse'])->middleware(['auth:api', 'admin']);
    Route::get('/{venue}', [VenueController::class, 'show']);
    Route::patch('/{venue}', [VenueController::class, 'update'])->middleware(['auth:api', 'admin']);
    Route::delete('/{venue}', [VenueController::class, 'destroy'])->middleware(['auth:api', 'admin']);
});
