<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Providers;

use Illuminate\Support\ServiceProvider;

class WebhooksServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind webhook repositories and services
        // $this->app->bind(WebhookRepositoryInterface::class, WebhookRepository::class);
        // $this->app->singleton(WebhookService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes for webhook endpoints
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
