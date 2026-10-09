<?php

declare(strict_types=1);

namespace Nabilet\Modules\HallSchemas\Providers;

use Illuminate\Support\ServiceProvider;

class HallSchemasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind hall schema services
    }

    public function boot(): void
    {
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.
    }
}
