<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The API surface is described by docs/openapi.yaml (81 paths, 98 operations) and
 * is implemented by the modules — not here.
 *
 * Nothing is registered yet on purpose. Pointing 98 routes at controllers that do
 * not exist would make the application fail to boot; starting with a tiny working
 * surface keeps `php artisan serve` green while P2 fills the modules in.
 *
 * When a module gains a routes/api.php of its own, the loop below picks it up, so
 * this file never needs editing per module and a disabled module simply contributes
 * nothing.
 */

Route::get('/ping', static function (): array {
    return ['data' => ['status' => 'ok']];
})->name('api.ping');

foreach (glob(base_path('app/Modules/*/routes/api.php')) ?: [] as $moduleRoutes) {
    require $moduleRoutes;
}
