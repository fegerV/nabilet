<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Backups\Http\Controllers\Admin\BackupController;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// Ручное скачивание локальных бэкапов — чувствительная операция (данные
// покупателей), поэтому `admin` поверх `auth:api`: только сотрудник с ролью
// admin. Список и скачивание — под одним префиксом.
Route::prefix('admin/backups')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/', [BackupController::class, 'index']);
    // Строгий паттерн имени файла дополнительно к проверке в контроллере.
    Route::get('/{file}', [BackupController::class, 'download'])
        ->where('file', '[A-Za-z0-9._-]+');
});
