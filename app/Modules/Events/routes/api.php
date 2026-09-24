<?php

declare(strict_types=1);

use Nabilet\Modules\Events\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

// The global apiPrefix in bootstrap/app.php already mounts this file at /api/v1.
// Repeating a version segment here produced /api/v1/v1/events, so every route in
// this file was unreachable at the path the spec declares (spec: /api/v1/events).
Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::get('/by-slug/{slug}', [EventController::class, 'showBySlug']);
    Route::post('/', [EventController::class, 'store'])->middleware(['auth:sanctum', 'admin']);
    Route::get('/{event}', [EventController::class, 'show']);
    Route::put('/{event}', [EventController::class, 'update'])->middleware(['auth:sanctum', 'admin']);
    Route::patch('/{event}', [EventController::class, 'update'])->middleware(['auth:sanctum', 'admin']);
    Route::delete('/{event}', [EventController::class, 'destroy'])->middleware(['auth:sanctum', 'admin']);
});
