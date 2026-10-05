<?php

declare(strict_types=1);

// NOTE: this file is `require`d from routes/api.php (see lines there), i.e. it is
// an included route definition, NOT a PSR-4 autoloadable unit. A namespace
// declaration in an included file would make the fully-qualified controller name
// resolve to Nabilet\Modules\Installer\Http\Controllers\... only by accident of
// the declared namespace matching PSR-4; the canonical convention for module
// route files in this project is the global namespace with fully-qualified
// imports. Declaring a namespace here also breaks any tool that treats the file
// as class-less code under PSR-4 rules.

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Installer\Http\Controllers\InstallerController;

/*
 * Installer Routes
 * 
 * Маршруты установщика доступны только если система еще не установлена.
 * После установки доступ блокируется через storage/install.lock
 */

// Web routes для установщика (HTML форма)
Route::middleware(['web'])->group(function () {
    Route::get('/install', [InstallerController::class, 'show'])->name('installer.show');
    Route::post('/install', [InstallerController::class, 'install'])->name('installer.install');
});

// API routes для установщика (JSON ответы)
Route::middleware(['api'])->prefix('api/installer')->group(function () {
    Route::get('/requirements', [InstallerController::class, 'show'])->name('installer.api.requirements');
    Route::post('/install', [InstallerController::class, 'install'])->name('installer.api.install');
});
