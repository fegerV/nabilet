<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Modules\Core\Users\Http\Controllers\UserController;

/*
 * Users Module API Routes
 */

Route::prefix('api/v1')->middleware(['auth:sanctum'])->group(function () {
    // User management
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{publicId}', [UserController::class, 'show'])->name('users.show');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{publicId}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{publicId}', [UserController::class, 'destroy'])->name('users.destroy');
    
    // User roles
    Route::post('/users/{publicId}/roles', [UserController::class, 'assignRole'])->name('users.roles.assign');
    Route::delete('/users/{publicId}/roles/{roleId}', [UserController::class, 'removeRole'])->name('users.roles.remove');
    
    // User search
    Route::get('/users/search', [UserController::class, 'search'])->name('users.search');
});
