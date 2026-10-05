<?php

declare(strict_types=1);

use Nabilet\Modules\Sessions\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
// `whereNumber('session')`: неявная привязка модели приводит путь к int, поэтому
// `/sessions/1abc` отдавал сеанс 1 — не тот ресурс, что запрошен. С ограничением
// такой путь даёт 404 (fail-closed).
Route::prefix('sessions')->group(function () {
    Route::get('/', [SessionController::class, 'index']);
    Route::post('/', [SessionController::class, 'store'])->middleware(['auth:api', 'admin']);
    Route::get('/{session}', [SessionController::class, 'show'])->whereNumber('session');
    Route::patch('/{session}', [SessionController::class, 'update'])->middleware(['auth:api', 'admin'])->whereNumber('session');
    Route::delete('/{session}', [SessionController::class, 'destroy'])->middleware(['auth:api', 'admin'])->whereNumber('session');
});
