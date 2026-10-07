<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Providers;

use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Observers\OrderObserver;
use Nabilet\Modules\Orders\Repositories\OrderRepository;
use Nabilet\Modules\Orders\Services\OrderService;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderRepository::class);
        $this->app->singleton(OrderService::class);
    }

    public function boot(): void
    {
        // Маршруты модуля НЕ грузим отсюда: они подключены централизованно в
        // routes/api.php, откуда получают префикс /api/v1. Загрузка через
        // loadRoutesFrom вынесла бы их в корень без префикса — именно поэтому
        // провайдеры модулей не регистрируются скопом.
        //
        // Обсервер — единственный подписчик на смену статуса заказа: отсюда
        // уходят письма и исходящие вебхуки. Провайдер зарегистрирован явно в
        // bootstrap/app.php, иначе он не boots и обсервер никогда не вешается.
        Order::observe(OrderObserver::class);
    }
}
