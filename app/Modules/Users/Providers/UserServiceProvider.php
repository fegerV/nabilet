<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use Illuminate\Support\ServiceProvider;

class UserServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind repository singleton
        $this->app->singleton(
            \App\Modules\Core\Users\Repositories\UserRepository::class,
            \App\Modules\Core\Users\Repositories\UserRepository::class
        );

        // Bind service singleton
        $this->app->singleton(
            \App\Modules\Core\Users\Services\UserService::class,
            \App\Modules\Core\Users\Services\UserService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes from Core/Users module
        $this->loadRoutesFrom(__DIR__ . '/../../Core/Users/routes/api.php');
    }
}
