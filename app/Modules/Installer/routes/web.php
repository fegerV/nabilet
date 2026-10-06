<?php

declare(strict_types=1);

// NOTE: this file is `require`d from routes/web.php (see the installer block
// there), i.e. it is an included route definition, NOT a PSR-4 autoloadable unit.
// A namespace declaration in an included file would make the fully-qualified
// controller name resolve to Nabilet\Modules\Installer\Http\Controllers\... only
// by accident of the declared namespace matching PSR-4; the canonical convention
// for module route files in this project is the global namespace with
// fully-qualified imports. Declaring a namespace here also breaks any tool that
// treats the file as class-less code under PSR-4 rules.
//
// It is loaded from routes/web.php (NOT routes/api.php) on purpose: routes there
// carry no api/v1 prefix, so the wizard stays reachable at the clean URL /install
// instead of /api/v1/install. CSRF on POST /install is disabled in
// bootstrap/app.php (validateCsrfTokens except) because the installer is a
// one-time, pre-auth setup endpoint gated by storage/install.lock.

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Installer\Http\Controllers\InstallerController;

/*
 * Installer Routes
 *
 * Маршруты установщика доступны только если система еще не установлена.
 * После установки доступ блокируется через storage/install.lock
 */

// Web routes для установщика (HTML форма + JSON для fetch из мастера)
Route::middleware(['web'])->group(function () {
    Route::get('/install', [InstallerController::class, 'show'])->name('installer.show');
    Route::post('/install', [InstallerController::class, 'install'])->name('installer.install');
});
