<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Loyalty\Observers\OrderBonusObserver;
use Nabilet\Modules\Loyalty\Observers\SessionObserver;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Loyalty\Services\FavoriteService;
use Nabilet\Modules\Loyalty\Services\FollowNotificationData;
use Nabilet\Modules\Loyalty\Services\FollowService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Sessions\Models\Session;

class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Оба сервиса stateless (работают через Eloquent), но объявляются
        // синглтонами по той же причине, что `MailSettings`: контроллеры
        // получают их из контейнера, и один экземпляр на запрос — это и есть
        // весь жизненный цикл, который им нужен.
        $this->app->singleton(FavoriteService::class);
        $this->app->singleton(FollowService::class);
        // Данные для писем подписчикам. Собираются из сеанса и подписчика, а
        // не из конфига, поэтому биндинг — обычный синглтон.
        $this->app->singleton(FollowNotificationData::class);
        // Бонусный счёт. Резолвится и из джобы начисления, и из контроллера
        // баланса — один экземпляр на запрос/джобу, как и у остальных сервисов
        // модуля.
        $this->app->singleton(BonusService::class);
    }

    public function boot(): void
    {
        // Маршруты НЕ грузятся отсюда: единственный владелец регистрации —
        // `routes/api.php`, из-под которого модули получают префикс `/api/v1`.
        // `loadRoutesFrom()` здесь зарегистрировал бы те же пути в корне, вне
        // API-группы middleware (см. `tools/verify-route-ownership.php`).
        //
        // Обсервер сеансов — единственная точка, откуда подписчикам уходят
        // письма о новой дате и открытии продаж. Провайдер обязан быть
        // зарегистрирован в `bootstrap/app.php` явно: провайдеры модулей не
        // бутятся скопом (см. `bootstrap/providers.php`), и без регистрации
        // обсервер не вешается — письма просто не уходят.
        Session::observe(SessionObserver::class);

        // Обсервер заказов — второй наблюдатель на ту же модель (первый,
        // `OrderObserver` модуля Orders, рассылает письма и вебхуки). Laravel
        // вызывает оба: `observe()` добавляет класс в список наблюдателей
        // модели, а не заменяет его. Здесь ловится оплата — единственный момент,
        // когда покупателю начисляется кэшбэк. Регистрация живёт в этом
        // провайдере, а не в `OrderServiceProvider`, потому что начисление —
        // знание клуба зрителей, а не заказов: модуль Orders не должен знать о
        // бонусном счёте.
        Order::observe(OrderBonusObserver::class);
    }
}
