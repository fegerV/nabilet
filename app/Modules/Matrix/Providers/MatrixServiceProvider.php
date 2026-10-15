<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Matrix\Observers\OrderMatrixObserver;
use Nabilet\Modules\Matrix\Services\MatrixClient;
use Nabilet\Modules\Matrix\Services\MatrixSettings;
use Nabilet\Modules\Orders\Models\Order;

class MatrixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Настройки канала. Синглтон по той же причине, что `MailSettings`:
        // аксессоры кэшируют прочитанные из БД значения на время жизни сервиса,
        // и один экземпляр на запрос/джобу — это и есть весь нужный жизненный
        // цикл. Клиент получает их через контейнер.
        $this->app->singleton(MatrixSettings::class);
        $this->app->singleton(MatrixClient::class);
    }

    public function boot(): void
    {
        // Маршруты НЕ грузятся отсюда: единственный владелец регистрации —
        // `routes/api.php`, из-под которого модуль получает префикс `/api/v1`.
        // `loadRoutesFrom()` здесь зарегистрировал бы те же пути в корне, вне
        // API-группы middleware (см. `tools/verify-route-ownership.php`).
        //
        // Настройки из админки применяются к runtime-конфигу здесь: значения
        // должны быть видны и веб-запросу, и воркеру очереди, и обоим — до
        // первой отправки.
        $this->app->make(MatrixSettings::class)->applyRuntimeConfig();

        // Наблюдатель оплаты заказа. Это шестой наблюдатель на `Order` (первые
        // пять — модулей Orders и Loyalty); Laravel вызывает все. Регистрация
        // именно здесь, а не в `OrderServiceProvider`, потому что уведомление в
        // Matrix — знание этого модуля, а не заказов: модуль Orders не должен
        // знать про Matrix.
        Order::observe(OrderMatrixObserver::class);
    }
}
