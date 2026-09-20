<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Providers;

use Illuminate\Support\ServiceProvider;

class OrganizationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind repository singleton
        $this->app->singleton(
            \App\Modules\Core\Organizations\Repositories\OrganizationRepository::class,
            \App\Modules\Core\Organizations\Repositories\OrganizationRepository::class
        );

        // Bind service singleton
        $this->app->singleton(
            \App\Modules\Core\Organizations\Services\OrganizationService::class,
            \App\Modules\Core\Organizations\Services\OrganizationService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes from Core/Organizations module
        $this->loadRoutesFrom(__DIR__ . '/../Core/Organizations/routes/api.php');
    }
}
