<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Modules\Auth\Domain\OAuthState;
use Nabilet\Modules\Auth\Domain\SocialIdentity;
use Nabilet\Modules\Auth\Domain\TelegramInitData;
use Nabilet\Modules\Auth\Http\Resources\AuthResource;
use Nabilet\Modules\Auth\Services\ClientContext;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Auth\Services\Social\SocialProviderRegistry;
use Nabilet\Modules\Auth\Services\SocialAuthService;

/**
 * Соц-вход (ТЗ §17): Telegram Mini App и OAuth-callback Яндекс/VK.
 *
 * ДВА РАЗНЫХ ТРАНСПОРТА, ОДИН РЕЗУЛЬТАТ. Telegram присылает `init_data` в теле
 * POST и ждёт JSON (`AuthResponse`); Яндекс/VK возвращают браузер на callback с
 * `code`/`state`, и ответ — 302 на витрину с токеном во фрагменте. В обоих
 * случаях токен выпускает `SessionIssuer` — тот же, что у логина по паролю, —
 * поэтому сессия соц-входа ничем не отличается от обычной и видна в `/me/*`.
 */
final class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $social,
        private readonly SessionIssuer $sessions,
        private readonly SocialProviderRegistry $providers,
    ) {
    }

    /**
     * `POST /api/v1/telegram/auth` — проверка `init_data` и выдача токена.
     *
     * Подпись проверяется ДО любого использования данных: `initDataUnsafe`
     * доверять нельзя (ТЗ §17). Невалидная/протухшая подпись — 401, не 500.
     */
    public function telegram(Request $request): JsonResponse
    {
        $initData = (string) $request->input('init_data', '');
        $botToken = (string) config('services.telegram.bot_token', '');

        $payload = $botToken === ''
            ? null
            : TelegramInitData::verify($initData, $botToken, time());

        $identity = $payload === null ? null : SocialIdentity::fromTelegram($payload);

        if ($identity === null) {
            throw new AuthError(
                'Telegram init data is invalid or expired.',
                'INVALID_TELEGRAM_AUTH',
                401,
            );
        }

        return $this->respondWithSession($request, $this->social->resolve($identity));
    }

    /**
     * `GET /auth/{provider}/start` — начало входа: выдать `state` и уйти к провайдеру.
     *
     * `state` кладётся в `HttpOnly`-куку и в authorize-URL. На callback значения
     * сверяются (`OAuthState::matches()`); без этого чужой валидный `code`
     * подсовывался бы жертве и входил в аккаунт злоумышленника (login CSRF).
     *
     * Ненастроенный провайдер возвращает браузер на витрину с ошибкой, а не 500:
     * кнопку в этом случае фронт не рисует, но прямой заход по адресу не должен
     * выглядеть как сбой сервера.
     */
    public function start(Request $request, string $provider): RedirectResponse
    {
        $client = $this->providers->for($provider);

        if ($client === null) {
            return $this->redirectWithError('unknown_provider');
        }

        $state = OAuthState::issue();

        try {
            $authorizeUrl = $client->authorizeUrl($state);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithError('provider_not_configured');
        }

        // `HttpOnly` — фронт значение не читает; `lax` — провайдер возвращает
        // браузер обычным GET-переходом, и кука обязана уехать с ним.
        return redirect()->away($authorizeUrl)->withCookie(cookie(
            name: OAuthState::COOKIE,
            value: $state,
            minutes: intdiv(OAuthState::TTL_SECONDS, 60),
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * `GET /auth/{provider}/callback` — OAuth-возврат Яндекс/VK.
     *
     * Неизвестный провайдер и неудачный обмен одинаково возвращают браузер на
     * витрину с `error`, а не показывают JSON: это переход из браузера, и
     * пользователь должен увидеть экран входа, а не тело ошибки.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $client = $this->providers->for($provider);

        if ($client === null) {
            return $this->redirectWithError('unknown_provider');
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '' || $state === '') {
            return $this->redirectWithError('missing_code');
        }

        // Сверка `state` — ДО обмена кода: обмен это сетевой вызов и создание
        // пользователя, и делать их ради чужого `code` незачем.
        $issued = $request->cookie(OAuthState::COOKIE);

        if (! OAuthState::matches(is_string($issued) ? $issued : null, $state)) {
            return $this->redirectWithError('invalid_state')->withoutCookie(OAuthState::COOKIE);
        }

        try {
            $identity = $client->exchange($code, $state);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithError('exchange_failed')->withoutCookie(OAuthState::COOKIE);
        }

        $user = $this->social->resolve($identity);
        $token = $this->sessions->issue((int) $user->getKey(), ClientContext::fromRequest($request));

        return redirect()->away($this->frontendUrl() . '?token=' . urlencode($token))
            ->withoutCookie(OAuthState::COOKIE);
    }

    private function respondWithSession(Request $request, \Nabilet\Modules\Core\Users\Models\User $user): JsonResponse
    {
        $token = $this->sessions->issue((int) $user->getKey(), ClientContext::fromRequest($request));

        return response()->json([
            'data' => [
                'user' => new AuthResource($user->load('roles')),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    private function redirectWithError(string $code): RedirectResponse
    {
        return redirect()->away($this->frontendUrl() . '?error=' . urlencode($code));
    }

    /**
     * Куда вернуть браузер: hash-маршрут витрины, принимающий токен/ошибку.
     *
     * База — `APP_URL`, а не относительный путь: callback открывается со стороны
     * провайдера, и относительный редирект увёл бы на домен провайдера.
     */
    private function frontendUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/#/auth/callback';
    }
}
