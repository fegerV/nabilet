<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Loyalty\Observers\SessionObserver;
use Nabilet\Modules\Loyalty\Services\FavoriteService;
use Nabilet\Modules\Loyalty\Services\FollowNotificationData;
use Nabilet\Modules\Loyalty\Services\FollowService;
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
    }
}
