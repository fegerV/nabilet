<?php

declare(strict_types=1);

use Nabilet\Modules\Sessions\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('sessions')->group(function () {
    Route::get('/', [SessionController::class, 'index']);
    Route::get('/{session}', [SessionController::class, 'show']);
});
