<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * Проверенная внешняя идентичность — то, что провайдер подтвердил о человеке.
 *
 * Создаётся ТОЛЬКО после проверки ответа провайдера (валидная подпись Telegram
 * `initData` или успешный обмен OAuth-кода). Дальше `SocialAuthService` решает
 * по ней, к какому пользователю привязать вход, — и это единственное, что ему
 * нужно знать о провайдере.
 *
 * Чистый объект: ни HTTP, ни БД, ни `config()`/`app()`, поэтому живёт в
 * `Auth/Domain` и проверяется dependency-free раннером.
 */
final class SocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerUserId,
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $avatarUrl = null,
    ) {
        if ($providerUserId === '') {
            throw new \InvalidArgumentException('Social identity requires a non-empty provider user id.');
        }
    }

    /**
     * Идентичность из проверенного Telegram `initData`.
     *
     * Telegram не отдаёт e-mail — пользователь создаётся без адреса (колонка
     * `users.email` NULLABLE именно для этого). `null` означает, что в payload
     * нет `user.id`, то есть подтверждать нечего.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromTelegram(array $payload): ?self
    {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $id = $user['id'] ?? null;

        if ($id === null || (string) $id === '') {
            return null;
        }

        return new self(
            provider: SocialProvider::TELEGRAM,
            providerUserId: (string) $id,
            email: null,
            firstName: isset($user['first_name']) ? (string) $user['first_name'] : null,
            lastName: isset($user['last_name']) ? (string) $user['last_name'] : null,
            avatarUrl: null,
        );
    }

    public function fullName(): ?string
    {
        $name = trim(((string) $this->firstName) . ' ' . ((string) $this->lastName));

        return $name === '' ? null : $name;
    }
}
