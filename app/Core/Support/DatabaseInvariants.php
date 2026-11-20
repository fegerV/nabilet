<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Чистая проверка версии сервера БД: исполняет ли он CHECK-ограничения.
 *
 * ОДИН ВЛАДЕЛЕЦ ПРАВИЛА. Раньше порог 8.0.16 / 10.2.1 жил в приватном методе
 * инсталлятора, а health-проверка о версии ничего не знала: установщик писал
 * предупреждение в журнал, а через час о нём забывали. Теперь и визард, и
 * самопроверка системы спрашивают этот класс — порог нельзя развести молча.
 *
 * MySQL до 8.0.16 и MariaDB до 10.2.1 ПРИНИМАЮТ синтаксис CHECK, но не
 * исполняют его. Миграция при этом помечается как выполненная, и «успешная»
 * установка выглядит защищённой, хотя инварианты сумм и статусов держит
 * только PHP.
 */
final class DatabaseInvariants
{
    public const MYSQL_MINIMUM = '8.0.16';

    public const MARIADB_MINIMUM = '10.2.1';

    public static function isMariaDb(string $version): bool
    {
        return stripos($version, 'mariadb') !== false;
    }

    /** Минимальная версия, которая реально исполняет CHECK. */
    public static function requiredMinimum(string $version): string
    {
        return self::isMariaDb($version) ? self::MARIADB_MINIMUM : self::MYSQL_MINIMUM;
    }

    /**
     * null — версия неизвестна или CHECK исполняются; иначе порог для сообщения.
     */
    public static function missingCheckSupport(string $version): ?string
    {
        if (trim($version) === '') {
            return null;
        }

        $numeric = preg_replace('/[^0-9.].*$/', '', $version) ?? '';
        $minimum = self::requiredMinimum($version);

        return version_compare($numeric, $minimum, '>=') ? null : $minimum;
    }
}
