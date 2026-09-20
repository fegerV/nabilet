<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Providers;

use Illuminate\Support\ServiceProvider;

class PrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind privacy services
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
