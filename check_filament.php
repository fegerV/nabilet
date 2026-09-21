<?php
echo "Testing Filament boot...\n";
try {
    $app = require __DIR__ . '/bootstrap/app.php';
    echo "App boot OK\n";

    $router = $app->make('router');
    $routes = collect($router->getRoutes()->getRoutes())->filter(function($r) {
        return str_contains($r->uri(), 'admin');
    });
    echo "Admin routes: " . $routes->count() . "\n";
    foreach ($routes as $r) {
        echo "  " . $r->uri() . " [" . implode(',', $r->methods()) . "]\n";
    }

    echo "\nFilament is ready!\n";
    echo "Access at: http://localhost:8000/admin\n";
    echo "Login: admin@nabilet.local / admin123\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}