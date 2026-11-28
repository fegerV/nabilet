<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Support;

/**
 * Словарь полномочий внешнего ИИ-агента.
 *
 * Каждый токен обязательно несёт `agent:read` (базовый доступ к данным своей
 * организации) и, опционально, узкие write-способности. Чтение — не «отсутствие
 * запрета»: отдельная способность, без неётокен на `/mcp` не пройдёт.
 *
 * Write-инструменты проверяют СВОЮ способность внутри handle(), а не только
 * на маршруте: один и тот же сервер обслуживает и read-, и write-токены.
 * Аннотации инструментов при этом честны: write-инструмент НЕ помечен
 * IsReadOnly, даже если он лишь готовит действие к подтверждению человеком.
 */
final class AgentCapabilities
{
    public const READ = 'agent:read';
    public const SYSTEM_READ = 'agent:system:read';
    public const CONTENT_READ = 'agent:content:read';
    public const EVENTS_WRITE = 'agent:events:write';
    public const SCHEMAS_PUBLISH = 'agent:schemas:publish';
    public const REFUNDS_WRITE = 'agent:refunds:write';
    public const ACTIONS_CONFIRM = 'agent:actions:confirm';
    public const CONTENT_WRITE = 'agent:content:write';

    /** @return array<string, string> capability => человекочитаемое название */
    public static function labels(): array
    {
        return [
            self::READ => 'Чтение бизнес-данных своей организации',
            self::SYSTEM_READ => 'Глобальная системная диагностика (только системный администратор)',
            self::CONTENT_READ => 'Чтение контента своей организации',
            self::EVENTS_WRITE => 'Создание черновиков мероприятий',
            self::SCHEMAS_PUBLISH => 'Публикация схем залов',
            self::REFUNDS_WRITE => 'Подготовка возвратов',
            self::ACTIONS_CONFIRM => 'Исполнение подтверждённых действий',
            self::CONTENT_WRITE => 'Подготовка изменений контента и витрины',
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function isKnown(string $capability): bool
    {
        return array_key_exists($capability, self::labels());
    }

    /** @param list<string> $capabilities */
    public static function hasAny(?object $user, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (self::has($user, $capability)) {
                return true;
            }
        }

        return false;
    }

    /** Токен владеет способностью? */
    public static function has(?object $user, string $capability): bool
    {
        if ($user === null || ! method_exists($user, 'tokenCan') || ! self::isKnown($capability)) {
            return false;
        }

        // Sanctum считает ability '*' универсальным: tokenCan() возвращает true
        // для ЛЮБОЙ строки. Такой токен обходит явное разделение полномочий и
        // получает доступ к глобальной диагностике. Универсальные токены не
        // являются носителями агентных способностей — способности выдаются
        // только списком agent:* из админки/команды.
        if (method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if (is_object($token) && isset($token->abilities)
                && in_array('*', (array) $token->abilities, true)) {
                return false;
            }
        }

        return $user->tokenCan($capability);
    }
}
