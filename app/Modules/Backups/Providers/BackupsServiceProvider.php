<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Backups\Services\YandexDiskSettings;

class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(YandexDiskSettings::class);
    }

    public function boot(): void
    {
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.

        // Токен из админки (settings) должен быть виден сервису бэкапов и
        // команде, которые читают config(). Без этого подключение через UI
        // сохранялось бы в БД, но не работало бы до правки .env.
        $this->app->make(YandexDiskSettings::class)->applyRuntimeConfig();
    }
}
