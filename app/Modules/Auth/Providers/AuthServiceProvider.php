<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind authentication repositories and services if needed
        // $this->app->bind(AuthRepositoryInterface::class, AuthRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes for authentication endpoints
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Configure Sanctum if not already configured in bootstrap
        // Sanctum::ignoreMigrations(); // Uncomment if using custom migrations
    }
}
