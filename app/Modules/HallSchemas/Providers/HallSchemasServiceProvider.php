<?php

declare(strict_types=1);

namespace App\Modules\HallSchemas\Providers;

use Illuminate\Support\ServiceProvider;

class HallSchemasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind hall schema services
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
