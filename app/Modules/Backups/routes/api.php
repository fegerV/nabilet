<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Backups\Http\Controllers\Admin\BackupController;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// Ручное скачивание локальных бэкапов — чувствительная операция (данные
// покупателей), поэтому `admin` поверх `auth:api`: только сотрудник с ролью
// admin. Список и скачивание — под одним префиксом.
Route::prefix('admin/backups')->middleware(['auth:api', 'admin', \Nabilet\Core\Http\Middleware\EnsurePlatformAdmin::class])->group(function () {
    Route::get('/', [BackupController::class, 'index']);

    // Яндекс.Диск и управление бэкапами — объявлены ДО /{file},
    // иначе строгий паттерн имени перехватил бы эти пути.
    Route::get('/settings', [BackupController::class, 'settings']);
    Route::post('/yandex/client', [BackupController::class, 'saveClientId']);
    Route::post('/yandex/token', [BackupController::class, 'saveToken']);
    Route::post('/yandex/disconnect', [BackupController::class, 'disconnect']);
    Route::get('/yandex/test', [BackupController::class, 'testConnection']);
    Route::get('/remote', [BackupController::class, 'remote']);
    Route::post('/run', [BackupController::class, 'run']);

    // Строгий паттерн имени файла дополнительно к проверке в контроллере.
    Route::get('/{file}', [BackupController::class, 'download'])
        ->where('file', '[A-Za-z0-9._-]+');
});
