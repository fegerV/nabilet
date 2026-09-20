<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::post('/', [EventController::class, 'store']);
    Route::get('/{event}', [EventController::class, 'show']);
    Route::put('/{event}', [EventController::class, 'update']);
    Route::patch('/{event}', [EventController::class, 'update']);
    Route::delete('/{event}', [EventController::class, 'destroy']);
});
