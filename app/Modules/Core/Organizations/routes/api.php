<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Core\Organizations\Http\Controllers\OrganizationController;
use Nabilet\Modules\Core\Organizations\Http\Middleware\CheckOrganizationAccess;

/*
 * Organizations Module API Routes
 */

// Mounted at /api/v1 by bootstrap/app.php — the prefix used to be repeated here,
// which produced /api/v1/api/v1/organizations.
Route::middleware(['auth:sanctum'])->group(function () {
    // Organization management - list and create don't need org access check
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    
    // Organization management - read/update/delete require org access check
    Route::get('/organizations/{publicId}', [OrganizationController::class, 'show'])
        ->middleware(CheckOrganizationAccess::class)
        ->name('organizations.show');
    Route::put('/organizations/{publicId}', [OrganizationController::class, 'update'])
        ->middleware(CheckOrganizationAccess::class)
        ->name('organizations.update');
    Route::delete('/organizations/{publicId}', [OrganizationController::class, 'destroy'])
        ->middleware(CheckOrganizationAccess::class)
        ->name('organizations.destroy');
    
    // Organization members - require org access check
    Route::post('/organizations/{publicId}/members', [OrganizationController::class, 'addMember'])
        ->middleware(CheckOrganizationAccess::class)
        ->name('organizations.members.add');
    Route::delete('/organizations/{publicId}/members/{userId}', [OrganizationController::class, 'removeMember'])
        ->middleware(CheckOrganizationAccess::class)
        ->name('organizations.members.remove');
});
