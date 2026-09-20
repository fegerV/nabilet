<?php

declare(strict_types=1);

use App\Modules\Sessions\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/sessions')->group(function () {
    Route::get('/', [SessionController::class, 'index']);
    Route::get('/{session}', [SessionController::class, 'show']);
});
