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
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.
    }
}
