<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer;

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
