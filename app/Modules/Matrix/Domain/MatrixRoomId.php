<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Domain;

/**
 * Идентификатор комнаты Matrix: `!opaque:server`.
 *
 * ЗАЧЕМ ПРОВЕРЯТЬ ФОРМУ, А НЕ ПРОСТО ОТПРАВЛЯТЬ
 *   `room_id` вводит администратор вручную, скопировав его из Element X. Самая
 *   частая ошибка при копировании — потеря ведущего `!` или попадание алиаса
 *   комнаты (`#name:server`) вместо её идентификатора. Алиас Synapse принимает в
 *   пути Client-Server API только после резолвинга (`/directory/room/{alias}`), и
 *   `PUT /rooms/#name:server/send/...` отвечает 404 «Unknown room». Без проверки
 *   администратор увидел бы «не удалось отправить» без объяснения, а формально
 *   ошибка — в одном символе.
 *
 * ЧТО ИМЕННО ДОПУСКАЕТСЯ (спецификация Matrix, «Opaque identifiers»)
 *   opaque-часть — `[0-9a-zA-Z._=/-]+`, имя сервера — hostname с необязательным
 *   портом. Регулярное выражение здесь — не парсер Matrix (он разрешает
 *   произвольные байты), а защита от ввода алиаса и от мусора; всё, что прошло
 *   проверку, Synapse дополнительно отвергнет своим 400/404, и это уже видно
 *   администратору в тест-отправке.
 *
 * Класс фреймворк-фри (см. `tools/verify-purity.php`): его можно проверить
 * `php tests/run.php` без `vendor/` и без базы.
 */
final class MatrixRoomId
{
    /** Opaque-часть идентификатора комнаты. */
    private const OPAQUE = '[0-9A-Za-z._=/-]+';

    /** Имя сервера: hostname с необязательным портом. */
    private const SERVER = '[A-Za-z0-9.-]+(?::[0-9]{1,5})?';

    /** Убрать обрамляющие пробелы — вставка из Element X часто тащит перевод строки. */
    public static function normalize(string $value): string
    {
        return trim($value);
    }

    public static function isValid(string $value): bool
    {
        $value = self::normalize($value);

        if ($value === '' || $value[0] !== '!') {
            // Пустая строка и алиас (`#...`) отсекаются здесь же.
            return false;
        }

        // Разделитель `#`, а не `/`: opaque-часть ЛЕГАЛЬНО содержит `/`, и с
        // косой чертой в роли разделителя PHP закрыл бы шаблон прямо внутри
        // класса символов — проверка молча возвращала бы false на верном ID.
        return (bool) preg_match('#^!' . self::OPAQUE . ':' . self::SERVER . '$#', $value);
    }
}
