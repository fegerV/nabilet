<?php

declare(strict_types=1);

namespace Nabilet\Modules\AbTesting\Providers;

use Illuminate\Support\ServiceProvider;

class AbTestingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind A/B testing services
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
