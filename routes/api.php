<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * API Routes - v1
 * 
 * The API surface is described by docs/openapi.yaml (81 paths, 98 operations) and
 * is implemented by the modules — not here.
 *
 * Module routes are loaded from app/Modules/*/routes/api.php files automatically.
 */

Route::get('/ping', static function (): array {
    return ['data' => ['status' => 'ok']];
})->name('api.ping');

// Load module-specific API routes
foreach (glob(base_path('app/Modules/*/routes/api.php')) ?: [] as $moduleRoutes) {
    require $moduleRoutes;
}
