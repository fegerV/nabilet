<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Провайдер модуля System.
 *
 * РОУТЫ ЗДЕСЬ НЕ ГРУЗЯТСЯ — и это не упущение.
 *
 * `routes/api.php` подключает `app/Modules/System/routes/api.php`
 * централизованно. Провайдер не должен вызывать `loadRoutesFrom()`, по двум
 * причинам, и обе уже были воспроизведены в этом проекте на модуле SEO:
 *
 *   1. ПРЕФИКС. `withRouting(apiPrefix: 'api/v1')` из `bootstrap/app.php`
 *      применяет префикс к файлу, указанному в конфигурации. Файл,
 *      загруженный провайдером, в этот пайплайн не попадает и регистрируется
 *      в корне — `/health` вместо `/api/v1/health`, то есть мимо контракта из
 *      `nabilet_core_spec/openapi.yaml`.
 *   2. ФАТАЛ. `ServiceProvider::loadRoutesFrom()` не проверяет существование
 *      файла: `require` по несуществующему пути — это `Error`, а не
 *      `Exception`. Провайдер вызывал `loadRoutesFrom(__DIR__ . '/../routes/
 *      api.php')` при том, что каталога `routes/` в модуле не было вообще.
 *      Не стреляло это только потому, что провайдер не зарегистрирован ни в
 *      `bootstrap/providers.php`, ни среди бутящихся провайдеров
 *      `config/nabilet.php`. То есть баг был не исправлен, а спрятан: первый
 *      же, кто добавил бы провайдер в реестр, получил бы фатал при загрузке
 *      приложения.
 *
 * ЧТО РЕГИСТРИРОВАТЬ ЗДЕСЬ
 *   Биндинги и сервисы — в `register()`. `HealthProbe` и контроллеры
 *   разрешаются контейнером автоматически (конструктор с типизированными
 *   зависимостями), поэтому биндинги пока не нужны.
 */
class SystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Биндинги не требуются: `HealthProbe` без зависимостей, контроллеры
        // получают его через конструктор.
    }
}
