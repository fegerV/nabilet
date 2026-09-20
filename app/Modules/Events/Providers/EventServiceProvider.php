<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use App\Modules\Events\Repositories\EventRepository;
use App\Modules\Events\Services\EventService;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventRepository::class);
        $this->app->singleton(EventService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
