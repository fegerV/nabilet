<?php

declare(strict_types=1);

namespace Nabilet\Modules\Users\Providers;

use Illuminate\Support\ServiceProvider;

class UsersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind user services
    }

    public function boot(): void
    {
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.
    }
}
