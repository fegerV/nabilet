<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Kiosk\Services\KioskTokenIssuer;

/**
 * Провайдер модуля киосков.
 *
 * Намеренно НЕ вызывает `loadRoutesFrom()`: маршруты подключаются центрально
 * в `routes/api.php` внутри группы `/api/v1`, иначе они окажутся без API-
 * middleware и вне версионирования. Алиасы middleware тоже не регистрируются —
 * `AuthenticateKiosk` указывается классом прямо в маршрутах.
 */
final class KioskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KioskTokenIssuer::class);
    }

    public function boot(): void
    {
        //
    }
}
