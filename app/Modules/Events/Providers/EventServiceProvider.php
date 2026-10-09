<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Providers;

use Nabilet\Modules\Events\Domain\EventPublicationPolicy;
use Nabilet\Modules\Events\Repositories\EventRepository;
use Nabilet\Modules\Events\Services\EventPublicationService;
use Nabilet\Modules\Events\Services\EventService;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventRepository::class);
        $this->app->singleton(EventService::class);

        // Политика не имеет состояния — синглтон здесь ради того, чтобы
        // `EventPublicationService` не конструировал её на каждый запрос.
        $this->app->singleton(EventPublicationPolicy::class);
        $this->app->singleton(EventPublicationService::class);
    }

    public function boot(): void
    {
        // Маршруты модуля подключены централизованно в routes/api.php, внутри
        // группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —
        // в корне, без префикса и вне API-группы middleware. См.
        // tools/verify-route-ownership.php.
    }
}
