<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Providers;

use Nabilet\Modules\Orders\Repositories\OrderRepository;
use Nabilet\Modules\Orders\Services\OrderService;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderRepository::class);
        $this->app->singleton(OrderService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
