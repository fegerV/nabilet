<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Analytics\Http\Controllers\MetrikaController;

// Глобальный apiPrefix в bootstrap/app.php монтирует файл в /api/v1.
// Эндпоинт публичный: SPA читает его до авторизации. Секретов здесь нет —
// только ID счётчика и имена целей (MetrikaSettings::publicConfig()).
Route::prefix('analytics')->group(function () {
    Route::get('/metrika/config', [MetrikaController::class, 'config']);
});
