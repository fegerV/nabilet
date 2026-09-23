<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/ping', fn() => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));

/*
|--------------------------------------------------------------------------
| Module Routes — Safe Route Loader
|--------------------------------------------------------------------------
|
| All modules are now enabled. Missing classes have been created as
| aliases/extensions to resolve the DI chain.
*/

if (file_exists(__DIR__ . '/../app/Modules/Auth/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Auth/routes/api.php';
}

$moduleRoutes = [
    __DIR__ . '/../app/Modules/Events/routes/api.php',
    __DIR__ . '/../app/Modules/Sessions/routes/api.php',
    __DIR__ . '/../app/Modules/Venues/routes/api.php',
    __DIR__ . '/../app/Modules/Inventory/routes/api.php',
    __DIR__ . '/../app/Modules/Cart/routes/api.php',
    __DIR__ . '/../app/Modules/Orders/routes/api.php',
    __DIR__ . '/../app/Modules/Payments/routes/api.php',
    __DIR__ . '/../app/Modules/Tickets/routes/api.php',
    __DIR__ . '/../app/Modules/Webhooks/routes/api.php',
    __DIR__ . '/../app/Modules/Core/Users/routes/api.php',
    __DIR__ . '/../app/Modules/Core/Organizations/routes/api.php',
    __DIR__ . '/../app/Modules/Venues/Halls/routes/api.php',
    __DIR__ . '/../app/Modules/Seo/routes/api.php',
];

foreach ($moduleRoutes as $routeFile) {
    if (file_exists($routeFile)) {
        require $routeFile;
    }
}