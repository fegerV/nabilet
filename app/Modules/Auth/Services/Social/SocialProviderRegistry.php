<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services\Social;

/**
 * Провайдер по имени пути callback.
 *
 * Имя приходит из URL (`/auth/{provider}/callback`), поэтому оно обязано
 * сверяться со словарём `SocialProvider`, а не уходить в `app()` как есть:
 * неизвестный провайдер — это 404, а не попытка построить клиент по строке.
 */
final class SocialProviderRegistry
{
    /** @param array<string, SocialProviderClient> $clients */
    public function __construct(private readonly array $clients)
    {
    }

    public function for(string $provider): ?SocialProviderClient
    {
        return $this->clients[strtolower($provider)] ?? null;
    }
}
