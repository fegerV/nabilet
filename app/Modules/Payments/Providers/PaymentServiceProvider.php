<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Providers;

use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\Services\PaymentService;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentRepository::class);
        $this->app->singleton(PaymentService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
