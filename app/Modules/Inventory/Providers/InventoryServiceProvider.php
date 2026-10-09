<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Inventory\Console\ClearExpiredHoldsCommand;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.

        // Планировщик (routes/console.php) ссылается на `seats:clear-expired`.
        // Команда обязана быть зарегистрирована, иначе artisan schedule:run
        // падает целиком и просроченные брони мест never освобождаются.
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearExpiredHoldsCommand::class,
            ]);
        }
    }
}
