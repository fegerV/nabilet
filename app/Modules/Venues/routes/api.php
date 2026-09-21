<?php

declare(strict_types=1);

use Nabilet\Modules\Venues\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/venues')->group(function () {
    Route::get('/', [VenueController::class, 'index']);
    Route::get('/{venue}', [VenueController::class, 'show']);
});
