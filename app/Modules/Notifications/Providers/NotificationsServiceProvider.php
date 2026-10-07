<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Notifications\Services\MailSettings;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailSettings::class);
    }

    public function boot(): void
    {
        // Маршруты НЕ грузятся отсюда: они подключены централизованно в
        // routes/api.php, откуда получают префикс /api/v1. Через
        // loadRoutesFrom они зарегистрировались бы в корне без префикса —
        // ровно то, из-за чего провайдеры модулей не регистрируются скопом.
        //
        // SMTP-настройки из админки применяются к конфигу mail здесь, на
        // бутстрапе: письма шлются и из веб-запроса, и из воркера очереди, и
        // оба пути должны видеть одно и то же, иначе тестовое письмо из
        // админки уходит, а письмо о заказе — нет.
        $this->app->make(MailSettings::class)->applyRuntimeConfig();
    }
}
