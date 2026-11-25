<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Updater\Http\Controllers\Admin\UpdateController;

/*
 * Self-update: админские эндпоинты под тем же контуром, что и диагностика
 * (`app/Modules/System/routes/api.php`). Файл подключается централизованно из
 * `routes/api.php` — загрузка из провайдера потеряла бы префикс /api/v1.
 *
 * Тяжёлая работа здесь НЕ выполняется: `start` и `rollback` только создают
 * запись и возвращают 202, а применяет изменения `nabilet:update:tick` по крону.
 */
Route::prefix('admin/system/update')
    ->middleware(['auth:api', 'admin', \Nabilet\Core\Http\Middleware\EnsurePlatformAdmin::class])
    ->group(function (): void {
        Route::get('/check', [UpdateController::class, 'check']);
        Route::post('/upload', [UpdateController::class, 'upload']);
        Route::post('/start', [UpdateController::class, 'start']);
        Route::get('/status', [UpdateController::class, 'status']);
        Route::post('/cancel', [UpdateController::class, 'cancel']);
        Route::post('/rollback', [UpdateController::class, 'rollback']);
        Route::get('/history', [UpdateController::class, 'history']);
    });
