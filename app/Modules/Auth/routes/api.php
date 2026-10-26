<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Auth\Http\Controllers\AuthController;
use Nabilet\Modules\Auth\Http\Controllers\MeController;
use Nabilet\Modules\Auth\Http\Controllers\SocialAuthController;

/*
|--------------------------------------------------------------------------
| Auth Module API Routes
|--------------------------------------------------------------------------
*/

/*
 * Telegram Mini App (ТЗ §17). Путь — `/api/v1/telegram/auth`, БЕЗ префикса
 * `auth`: контракт объявляет его на корне API, и `init_data` проверяется
 * server-side до любого использования данных.
 */
Route::post('/telegram/auth', [SocialAuthController::class, 'telegram'])->middleware('throttle:auth');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);

    /*
     * `throttle:auth` = 5 per minute per IP (`Nabilet\Core\Http\Middleware\RateLimiter`).
     * Both password endpoints need it: login for brute force, forgot-password
     * because it makes us send mail to an address the caller chose — without a
     * limit it is an open relay for spam aimed at anyone, from our domain.
     */
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');

    /*
     * Paths match `docs/openapi.yaml` (`/auth/password/forgot`, `/auth/password/reset`,
     * `/auth/email/verify`). They previously read `/forgot-password` and
     * `/reset-password`, which is what `docs/SERVER-HEALTH.md` recorded as a
     * contract divergence — a generated client would have called paths this
     * application does not serve.
     */
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
    Route::post('/email/verify', [AuthController::class, 'verifyEmail'])->middleware('auth:api');
});

/*
|--------------------------------------------------------------------------
| Личный кабинет — `/api/v1/me/*`
|--------------------------------------------------------------------------
|
| Пять путей, которые контракт объявлял с самого начала, а приложение не
| обслуживало ни одного (`docs/REVIEW-spec-bundle.md` §3.24.4: «`/me/*` (5)»
| среди незакрытых пространств имён). Живут в модуле Auth, потому что это
| идентичность, а не сущность: владелец здесь — всегда результат
| аутентификации, и ни один метод не принимает чужой идентификатор.
|
| `GET /me/orders` объявлен в модуле Orders (там же его скоуп), а не здесь:
| заказ — домен Orders, и маршрут обязан быть в том же файле, что и
| `GET /orders`, иначе скоупинг по владельцу придётся писать второй раз.
|
| Весь префикс закрыт `auth:api`. Без Bearer-токена ни один метод не имеет
| владельца, а `$request->user()` вернул бы `null` — то есть 401 здесь не
| украшение, а условие корректности.
*/
Route::prefix('me')->middleware('auth:api')->group(function () {
    Route::get('/', [MeController::class, 'show']);
    Route::patch('/', [MeController::class, 'update']);

    // `throttle:auth` — тот же лимитер, что у входа: подбор `current_password`
    // украденной сессией обязан упираться в счётчик, а не в скорость сети.
    Route::post('/password', [MeController::class, 'changePassword'])->middleware('throttle:auth');

    Route::get('/sessions', [MeController::class, 'sessions']);
    // `whereNumber` — по той же причине, что у `/tickets/{ticket}`: неявная
    // привязка привела бы путь к int, поэтому `/me/sessions/1abc` открывал бы
    // сессию 1.
    Route::delete('/sessions/{session}', [MeController::class, 'revokeSession'])->whereNumber('session');
});