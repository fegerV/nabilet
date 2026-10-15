<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Providers;

use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind content services
    }

    public function boot(): void
    {
        // Маршруты модуля живут в `app/Modules/Content/routes/web.php` и
        // подключены корневым `routes/web.php` ДО `Route::fallback()` (аудит
        // P-12 + стратегия SEO, B-2). Загрузка отсюда зарегистрировала бы их
        // второй раз — в корне, без префикса и вне порядка обхода обычных
        // маршрутов. См. `tools/verify-route-ownership.php`.
    }
}
