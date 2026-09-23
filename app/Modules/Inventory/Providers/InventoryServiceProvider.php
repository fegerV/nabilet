<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Inventory\Console\ClearExpiredHoldsCommand;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

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
