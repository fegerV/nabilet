<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Modules\Core\Organizations\Http\Controllers\OrganizationController;

/*
 * Organizations Module API Routes
 */

Route::prefix('api/v1')->middleware(['auth:sanctum'])->group(function () {
    // Organization management
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/organizations/{publicId}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::put('/organizations/{publicId}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::delete('/organizations/{publicId}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');

    // Organization members
    Route::post('/organizations/{publicId}/members', [OrganizationController::class, 'addMember'])->name('organizations.members.add');
    Route::delete('/organizations/{publicId}/members/{userId}', [OrganizationController::class, 'removeMember'])->name('organizations.members.remove');
});
