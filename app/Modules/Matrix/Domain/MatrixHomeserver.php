<?php

declare(strict_types=1);

namespace Nabilet\Modules\Matrix\Domain;

/**
 * Базовый адрес homeserver'а Synapse.
 *
 * ЧТО ЗДЕСЬ РЕШАЕТСЯ
 *   Администратор вводит адрес так, как его видит в браузере: чаще всего без
 *   схемы («matrix.example.org»). Из этого значения собирается URL запроса к
 *   Client-Server API, поэтому схема обязана появиться ДО сборки: `parse_url()`
 *   на строке без схемы считает хостом пустоту, и запрос ушёл бы в никуда.
 *   Отсутствие схемы дополняется до `https://` — удалённый Synapse вне
 *   localhost без TLS не бывает, а «случайный» http означал бы токен доступа в
 *   открытом виде.
 *
 * ЧТО ОТВЕРГАЕТСЯ
 *   Пустая строка, адрес без хоста, схема кроме http/https (например
 *   `ftp://`, `file://`). `file://` особенно: он превратил бы отправку в
 *   локальное чтение файла, а это уже не «уведомление не ушло», а SSRF.
 *
 * Класс фреймворк-фри (см. `tools/verify-purity.php`).
 */
final class MatrixHomeserver
{
    /** Нормализованный базовый URL (без завершающего `/`) или null, если адрес негоден. */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Схема не указана — считаем её https. Проверяем именно `scheme://`,
        // а не просто наличие `://`: строка `example.org:8448` содержит `:` и
        // без этой оговорки была бы принята за URL с несуществующей схемой.
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return rtrim($url, '/');
    }

    public static function isValid(string $url): bool
    {
        return self::normalize($url) !== null;
    }
}
