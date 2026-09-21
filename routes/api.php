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
| Module Routes - Auto-loaded from each module's routes/api.php
|--------------------------------------------------------------------------
*/

// Events Module
if (file_exists(__DIR__ . '/../app/Modules/Events/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Events/routes/api.php';
}

// Sessions Module
if (file_exists(__DIR__ . '/../app/Modules/Sessions/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Sessions/routes/api.php';
}

// Venues Module
if (file_exists(__DIR__ . '/../app/Modules/Venues/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Venues/routes/api.php';
}

// Inventory Module
if (file_exists(__DIR__ . '/../app/Modules/Inventory/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Inventory/routes/api.php';
}

// Carts Module
if (file_exists(__DIR__ . '/../app/Modules/Carts/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Carts/routes/api.php';
}

// Orders Module
if (file_exists(__DIR__ . '/../app/Modules/Orders/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Orders/routes/api.php';
}

// Payments Module
if (file_exists(__DIR__ . '/../app/Modules/Payments/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Payments/routes/api.php';
}

// Tickets Module
if (file_exists(__DIR__ . '/../app/Modules/Tickets/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Tickets/routes/api.php';
}

// Webhooks Module
if (file_exists(__DIR__ . '/../app/Modules/Webhooks/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Webhooks/routes/api.php';
}

// Core Users Module
if (file_exists(__DIR__ . '/../app/Modules/Core/Users/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Core/Users/routes/api.php';
}

// Core Organizations Module
if (file_exists(__DIR__ . '/../app/Modules/Core/Organizations/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Core/Organizations/routes/api.php';
}

// Venues Halls Module
if (file_exists(__DIR__ . '/../app/Modules/Venues/Halls/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Venues/Halls/routes/api.php';
}
