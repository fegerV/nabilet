<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Providers;

use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\Services\PaymentService;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentRepository::class);
        $this->app->singleton(PaymentService::class);

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
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}
