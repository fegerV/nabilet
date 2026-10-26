<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * Поддерживаемые провайдеры соц-входа (ТЗ §17: Yandex, VK, Telegram Mini App).
 *
 * Единственный словарь имён провайдеров. Строки не выписываются по месту: имя
 * попадает в `social_accounts.provider` и в путь callback, и две записи одного
 * провайдера (`'Yandex'` и `'yandex'`) разошлись бы в две разные привязки.
 */
final class SocialProvider
{
    public const YANDEX = 'yandex';

    public const VK = 'vk';

    public const TELEGRAM = 'telegram';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::YANDEX, self::VK, self::TELEGRAM];
    }

    public static function isSupported(string $provider): bool
    {
        return in_array(strtolower($provider), self::all(), true);
    }
}
