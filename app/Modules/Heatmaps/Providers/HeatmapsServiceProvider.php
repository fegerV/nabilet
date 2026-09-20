<?php

declare(strict_types=1);

namespace App\Modules\Heatmaps\Providers;

use Illuminate\Support\ServiceProvider;

class HeatmapsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind heatmap services
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
