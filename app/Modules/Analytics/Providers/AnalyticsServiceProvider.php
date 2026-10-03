<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Analytics\Services\MetrikaSettings;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetrikaSettings::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
