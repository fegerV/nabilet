<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Analytics\Http\Controllers\MetrikaController;
use Nabilet\Modules\Analytics\Http\Controllers\MetrikaSettingsController;

// Глобальный apiPrefix в bootstrap/app.php монтирует файл в /api/v1.
// Эндпоинт публичный: SPA читает его до авторизации. Секретов здесь нет —
// только ID счётчика и имена целей (MetrikaSettings::publicConfig()).
Route::prefix('analytics')->group(function () {
    Route::get('/metrika/config', [MetrikaController::class, 'config']);
});

// Настройки Метрики для Vue-админки (#/admin/settings/metrika): только
// admin/manager (middleware `admin`, см. EnsureAdminRole), под Sanctum.
Route::prefix('admin/analytics/metrika')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [MetrikaSettingsController::class, 'show']);
    Route::put('/', [MetrikaSettingsController::class, 'update']);
});
