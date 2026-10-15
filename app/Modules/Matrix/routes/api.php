<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Matrix\Http\Controllers\MatrixSettingsController;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// Канал Matrix — административный: сообщения уходят в комнату, которую читают
// сотрудники, и настраивается он токеном доступа к Synapse. Поэтому весь раздел
// под `admin` поверх `auth:api`: покупателю здесь делать нечего, а токен — это
// полный доступ к аккаунту бота.
Route::prefix('admin/matrix')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/settings', [MatrixSettingsController::class, 'show']);
    Route::put('/settings', [MatrixSettingsController::class, 'update']);
    Route::post('/test', [MatrixSettingsController::class, 'sendTest']);
});
