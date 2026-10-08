<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Providers;

use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Media\Services\MediaService;

/**
 * Провайдер модуля Media.
 *
 * ЧТО БЫЛО СЛОМАНО
 *
 * Единственным содержимым `boot()` был вызов
 * `loadRoutesFrom(__DIR__ . '/../routes/api.php')` — против каталога `routes/`,
 * которого не существовало. Провайдер при этом не был зарегистрирован в
 * `bootstrap/providers.php`, поэтому `boot()` не выполнялся ни разу, и ошибка
 * была не видна: модуль выглядел написанным.
 *
 * ЧТО ИЗМЕНИЛОСЬ И ПОЧЕМУ ТАК
 *
 * Маршруты модуля НЕ загружаются здесь. В проекте это общее правило, и оно
 * записано в `routes/api.php`: файлы модулей подключаются одним списком, чтобы
 * все они получили префикс `/api/v1`. Загрузка из провайдера даёт второй,
 * беспрефиксный набор маршрутов — именно так в модуле Tickets и появился
 * `/api/v1/v1/events`.
 *
 * Поэтому `boot()` пуст, а провайдер регистрируется ради `register()`: сервис
 * получает диск из конфигурации один раз, а не читает `config()` на каждом
 * вызове. Само по себе это не оптимизация — смысл в том, что диск становится
 * ОДНОЙ точкой решения. Пока он читается в сервисе по месту, тест не может
 * подменить его без правки конфигурации, а установка с S3 — переопределить,
 * не трогая код.
 */
class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaService::class, static function (): MediaService {
            $disk = config('nabilet.media.disk');

            return new MediaService(is_string($disk) && $disk !== '' ? $disk : null);
        });
    }

    public function boot(): void
    {
        // API-маршруты подключаются из routes/api.php под префиксом /api/v1.
        // Загружать их здесь нельзя — см. docblock класса.
    }
}
