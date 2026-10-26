<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services\Social;

use Illuminate\Support\Facades\Http;
use Nabilet\Modules\Auth\Domain\SocialIdentity;
use Nabilet\Modules\Auth\Domain\SocialProvider;

/**
 * Яндекс OAuth: код → access_token → профиль.
 *
 * Два запроса, как описано в документации Яндекс OAuth: обмен кода на токен
 * (`oauth.yandex.ru/token`) и чтение профиля (`login.yandex.ru/info`). Токен
 * передаётся заголовком `Authorization: OAuth <token>` — именно в этой форме
 * Яндекс его ждёт (не `Bearer`).
 */
final class YandexClient implements SocialProviderClient
{
    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $redirectUri = null,
    ) {
    }

    public function provider(): string
    {
        return SocialProvider::YANDEX;
    }

    /**
     * Страница входа Яндекса.
     *
     * `redirect_uri` берётся из той же конфигурации, что и у обмена кода: Яндекс
     * сверяет его с зарегистрированным в приложении и на authorize, и на token.
     * Разъехавшись, эти два значения дают отказ уже ПОСЛЕ ввода пароля — самый
     * дорогой момент, чтобы узнать о расхождении.
     */
    public function authorizeUrl(string $state): string
    {
        [$clientId] = $this->credentials();

        return 'https://oauth.yandex.ru/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, string $state): SocialIdentity
    {
        [$clientId, $clientSecret] = $this->credentials();

        $tokenResponse = Http::asForm()->post('https://oauth.yandex.ru/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('Yandex did not return an access token.');
        }

        $info = Http::withHeaders(['Authorization' => 'OAuth ' . $accessToken])
            ->get('https://login.yandex.ru/info', ['format' => 'json']);

        $id = $info->json('id');
        if ($id === null || (string) $id === '') {
            throw new \RuntimeException('Yandex did not return a user id.');
        }

        $email = $info->json('default_email');
        if (! is_string($email) || $email === '') {
            $email = null;
        }

        $avatarId = $info->json('default_avatar_id');
        $avatar = is_string($avatarId) && $avatarId !== ''
            ? 'https://avatars.yandex.net/get-yapic/' . $avatarId . '/islands-200'
            : null;

        return new SocialIdentity(
            provider: SocialProvider::YANDEX,
            providerUserId: (string) $id,
            email: $email,
            firstName: $this->stringOrNull($info->json('first_name')),
            lastName: $this->stringOrNull($info->json('last_name')),
            avatarUrl: $avatar,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * `[client_id, client_secret]` из аргументов или конфигурации.
     *
     * Одна проверка «настроен ли провайдер» на оба входа — и на authorize, и на
     * обмен: иначе один из них мог бы уйти в сеть с пустым `client_id`, а
     * сообщение об ошибке зависело бы от того, с какого шага начали.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        $clientId = $this->clientId ?? (string) config('services.yandex.client_id', '');
        $clientSecret = $this->clientSecret ?? (string) config('services.yandex.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Yandex OAuth is not configured.');
        }

        return [$clientId, $clientSecret];
    }

    /** `redirect_uri` из аргументов или конфигурации; пустой — отказ. */
    private function redirectUri(): string
    {
        $redirectUri = $this->redirectUri ?? (string) config('services.yandex.redirect_uri', '');

        if ($redirectUri === '') {
            throw new \RuntimeException('Yandex OAuth redirect URI is not configured.');
        }

        return $redirectUri;
    }
}
