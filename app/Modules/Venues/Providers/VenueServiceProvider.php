<?php

declare(strict_types=1);

namespace App\Modules\Venues\Providers;

use Illuminate\Support\ServiceProvider;

class VenueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
