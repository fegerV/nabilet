<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Providers;

use Illuminate\Support\ServiceProvider;

class CheckinServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind check-in repositories and services
        // $this->app->bind(CheckinRepositoryInterface::class, CheckinRepository::class);
        // $this->app->singleton(CheckinService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes for check-in endpoints
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Load views if needed
        // $this->loadViewsFrom(__DIR__ . '/../resources/views', 'checkin');
    }
}
