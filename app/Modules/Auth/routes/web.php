<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Auth\Http\Controllers\SocialAuthController;

/*
|--------------------------------------------------------------------------
| Auth — OAuth-callback (web)
|--------------------------------------------------------------------------
|
| Яндекс и VK возвращают БРАУЗЕР на `GET /auth/{provider}/callback?code=&state=`
| (пути без `/api/v1` — так объявлено в `openapi.yaml`, `yandexCallback` /
| `vkCallback`, ответ 302). Это web-маршрут, а не api: его открывает переход
| пользователя, и ответ — редирект на витрину, а не JSON.
|
| `whereIn` ограничивает провайдеров словарём `SocialProvider`: неизвестный
| сегмент пути не должен доходить до контроллера как имя класса.
|
| Подключается из `routes/web.php` ВЫШЕ `Route::fallback()`, иначе catch-all
| перехватил бы callback и вернул HTML-404.
*/

Route::get('/auth/{provider}/start', [SocialAuthController::class, 'start'])
    ->whereIn('provider', ['yandex', 'vk'])
    ->middleware('throttle:auth')
    ->name('auth.social.start');

Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['yandex', 'vk'])
    ->middleware('throttle:auth')
    ->name('auth.social.callback');
