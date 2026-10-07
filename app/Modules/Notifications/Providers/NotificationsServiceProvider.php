<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Providers;

use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind notification services
    }

    public function boot(): void
    {
        // Маршруты НЕ грузятся отсюда: они подключены централизованно в
        // routes/api.php, откуда получают префикс /api/v1. Через
        // loadRoutesFrom они зарегистрировались бы в корне без префикса —
        // ровно то, из-за чего провайдеры модулей не регистрируются скопом.
    }
}
