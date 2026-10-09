<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Providers;

use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\RefundService;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentRepository::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(RefundService::class);

        // Провайдеру нужны ключи/секреты из конфига — контейнер не может их
        // вывести (конструктор YooKassaProvider требует строки), поэтому создаём
        // вручную. В тестах ключи могут быть пустыми: это окей — провайдер
        // используется только при реальном запросе к API (refund).
        $this->app->singleton(YooKassaProvider::class, function () {
            return new YooKassaProvider(
                (string) config('nabilet.payment.yookassa.shop_id', ''),
                (string) config('nabilet.payment.yookassa.secret_key', ''),
                (string) config('nabilet.payment.yookassa.base_url', 'https://api.yookassa.ru/v3'),
            );
        });

        // Реестр провайдеров: единая точка выбора шлюза вместо разбросанных
        // match/app() внутри PaymentService. Новые провайдеры добавляются
        // здесь же (имя => FQCN или фабрика).
        $this->app->singleton(PaymentProviderRegistry::class, function ($app) {
            $registry = new PaymentProviderRegistry($app);
            $registry->register('yookassa', YooKassaProvider::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.
    }
}
