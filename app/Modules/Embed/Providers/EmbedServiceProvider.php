<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Providers;

use Illuminate\Support\ServiceProvider;

class EmbedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind embed services
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
