<?php

declare(strict_types=1);

use Nabilet\Modules\Events\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

// The global apiPrefix in bootstrap/app.php already mounts this file at /api/v1.
// Repeating a version segment here produced /api/v1/v1/events, so every route in
// this file was unreachable at the path the spec declares (spec: /api/v1/events).
Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::post('/', [EventController::class, 'store']);
    Route::get('/{event}', [EventController::class, 'show']);
    Route::put('/{event}', [EventController::class, 'update']);
    Route::patch('/{event}', [EventController::class, 'update']);
    Route::delete('/{event}', [EventController::class, 'destroy']);
});
