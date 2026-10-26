<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services\Social;

use Illuminate\Support\Facades\Http;
use Nabilet\Modules\Auth\Domain\SocialIdentity;
use Nabilet\Modules\Auth\Domain\SocialProvider;

/**
 * VK OAuth: код → access_token → профиль.
 *
 * Обмен кода (`oauth.vk.com/access_token`) возвращает `access_token`, `user_id`
 * и иногда `email` (если у приложения есть право на почту). Профиль читается
 * методом `users.get` с полем `photo_200`. `v` фиксирован: VK без версии
 * отвечает по умолчанию и может сменить формат.
 */
final class VkClient implements SocialProviderClient
{
    private const API_VERSION = '5.199';

    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $redirectUri = null,
    ) {
    }

    public function provider(): string
    {
        return SocialProvider::VK;
    }

    /**
     * Страница входа VK.
     *
     * `v` обязателен и здесь: без версии VK отвечает по умолчанию, а состав
     * полей ответа на `users.get` между версиями меняется.
     *
     * `redirect_uri` — тот же, что уйдёт в обмен кода: VK сверяет его с
     * доверенным адресом приложения и на authorize, и на access_token.
     */
    public function authorizeUrl(string $state): string
    {
        [$clientId] = $this->credentials();

        return 'https://oauth.vk.com/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'v' => self::API_VERSION,
        ]);
    }

    public function exchange(string $code, string $state): SocialIdentity
    {
        [$clientId, $clientSecret] = $this->credentials();
        $redirectUri = $this->redirectUri();

        $tokenResponse = Http::get('https://oauth.vk.com/access_token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'v' => self::API_VERSION,
        ]);

        $accessToken = $tokenResponse->json('access_token');
        $userId = $tokenResponse->json('user_id');

        if (! is_string($accessToken) || $accessToken === '' || $userId === null || (string) $userId === '') {
            throw new \RuntimeException('VK did not return an access token.');
        }

        $email = $tokenResponse->json('email');
        if (! is_string($email) || $email === '') {
            $email = null;
        }

        $profile = Http::get('https://api.vk.com/method/users.get', [
            'user_ids' => (string) $userId,
            'fields' => 'photo_200',
            'access_token' => $accessToken,
            'v' => self::API_VERSION,
        ]);

        $person = $profile->json('response.0');
        $person = is_array($person) ? $person : [];

        return new SocialIdentity(
            provider: SocialProvider::VK,
            providerUserId: (string) $userId,
            email: $email,
            firstName: $this->stringOrNull($person['first_name'] ?? null),
            lastName: $this->stringOrNull($person['last_name'] ?? null),
            avatarUrl: $this->stringOrNull($person['photo_200'] ?? null),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * `[client_id, client_secret]` из аргументов или конфигурации.
     *
     * Одна проверка «настроен ли провайдер» на оба шага: authorize не должен
     * уходить в сеть с пустым `client_id`, а сообщение об ошибке не должно
     * зависеть от того, с какого шага начали.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        $clientId = $this->clientId ?? (string) config('services.vk.client_id', '');
        $clientSecret = $this->clientSecret ?? (string) config('services.vk.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('VK OAuth is not configured.');
        }

        return [$clientId, $clientSecret];
    }

    /** `redirect_uri` из аргументов или конфигурации; пустой — отказ. */
    private function redirectUri(): string
    {
        $redirectUri = $this->redirectUri ?? (string) config('services.vk.redirect_uri', '');

        if ($redirectUri === '') {
            throw new \RuntimeException('VK OAuth redirect URI is not configured.');
        }

        return $redirectUri;
    }
}
