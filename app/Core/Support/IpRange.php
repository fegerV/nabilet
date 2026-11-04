<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Проверка «IP-адрес входит в диапазон» для записей IP-allowlist.
 *
 * Зачем это нужно
 *   `YOOKASSA_WEBHOOK_IP_ALLOWLIST` в `.env.example` и сообщение в логе
 *   `WebhookAuthenticator` обещали CIDR, а код сравнивал строки через
 *   `in_array($ip, $allowlist, true)`. То есть запись `185.32.0.0/24` не
 *   совпадала НИ С ОДНИМ адресом: оператор настраивал защиту по официальной
 *   подсети провайдера, получал пустой список рабочих адресов и 403 на всех
 *   уведомлениях. Защита при этом выглядела настроенной.
 *
 *   Класс намеренно не зависит от фреймворка и ничего не читает из окружения:
 *   правило одно и то же в бою и в тесте, и его можно проверить одним PHP.
 *
 * Что принимается
 *   - точный адрес: `203.0.113.10`, `2001:db8::1`;
 *   - CIDR: `203.0.113.0/24`, `2001:db8::/32`;
 *   - IPv4-адрес против IPv4-mapped записи (`::ffff:203.0.113.10`) и наоборот;
 *   - IPv6 с zone id (`fe80::1%eth0`) — зона отбрасывается, адрес остаётся.
 *
 * Чего класс не делает
 *   - не считает `*` диапазоном: звёздочка не адрес, и `isValid('*')` — `false`;
 *   - не «угадывает» мусор: `1.2.3.4.5`, `203.0.113.0/33`, пустая строка и
 *     строка с пробелами внутри дают `false` (fail-closed). Пробелы по краям
 *     срезаются: `config/nabilet.php` режет переменную по запятой без `trim`,
 *     поэтому `a, b` даёт запись с ведущим пробелом.
 */
final class IpRange
{
    /**
     * Входит ли адрес в запись allowlist.
     *
     * Любая нечитаемая сторона — `false`, а не исключение: проверка вызывается
     * на пути входящего вебхука, где исключение из-за кривой строки в `.env`
     * означало бы 500 на каждом уведомлении. Отказ здесь честнее: адрес не
     * подтверждён.
     */
    public static function contains(string $ip, string $range): bool
    {
        $address = self::packAddress($ip);

        if ($address === null) {
            return false;
        }

        $network = self::parseRange($range);

        if ($network === null) {
            return false;
        }

        $address = self::toNetworkFamily($address, $network['bytes'], $network['prefix']);

        if ($address === null) {
            return false;
        }

        return self::inNetwork($address, $network['bytes'], $network['prefix']);
    }

    /**
     * Является ли запись allowlist точным адресом или CIDR.
     *
     * Нужна производственной проверке конфигурации: малформированная запись не
     * отвергает приложение (иначе один лишний пробел в `.env` валил бы старт), но
     * обязана попасть в отчёт — иначе она молча не защищает.
     */
    public static function isValid(string $range): bool
    {
        return self::parseRange($range) !== null;
    }

    /**
     * Разбор записи в пару «сетевые байты + длина маски».
     *
     * @return array{bytes: string, prefix: int}|null
     */
    private static function parseRange(string $range): ?array
    {
        $range = trim($range);

        if ($range === '') {
            return null;
        }

        // Пробел внутри записи — опечатка (`203.0.113.0 /24`), а не формат.
        // Пробелы по краям уже срезаны; всё, что осталось, отвергаем целиком:
        // иначе запись «почти работает» и молча не защищает.
        if (preg_match('/\s/', $range) === 1) {
            return null;
        }

        $parts = explode('/', $range);

        if (count($parts) > 2) {
            return null;
        }

        $bytes = self::packAddress($parts[0]);

        if ($bytes === null) {
            return null;
        }

        $maxPrefix = strlen($bytes) === 4 ? 32 : 128;

        if (count($parts) === 1) {
            return ['bytes' => $bytes, 'prefix' => $maxPrefix];
        }

        $prefix = trim($parts[1]);

        // `ctype_digit` отсекает `-1`, `+24`, `24bit` и пустую строку. Ведущие
        // нули (`/024`) принимаются как число — маска от этого не меняется.
        if ($prefix === '' || ! ctype_digit($prefix)) {
            return null;
        }

        $bits = (int) $prefix;

        if ($bits > $maxPrefix) {
            return null;
        }

        return ['bytes' => $bytes, 'prefix' => $bits];
    }

    /**
     * Двоичное представление адреса: 4 байта для IPv4, 16 для IPv6.
     */
    private static function packAddress(string $address): ?string
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        // Zone id (`fe80::1%eth0`) есть только у IPv6 и в `inet_pton` не
        // проходит. Отбрасываем его лишь при наличии двоеточия: `1.2.3.4%eth0`
        // остаётся мусором, а не превращается в валидный IPv4.
        $percent = strpos($address, '%');

        if ($percent !== false) {
            if (! str_contains($address, ':')) {
                return null;
            }

            $address = substr($address, 0, $percent);
        }

        $packed = @inet_pton($address);

        if ($packed === false || (strlen($packed) !== 4 && strlen($packed) !== 16)) {
            return null;
        }

        return $packed;
    }

    /**
     * Приведение адреса к семейству сети.
     *
     * Семьи не смешиваются, кроме одного явного моста — IPv4-mapped диапазона
     * `::ffff:0:0/96` (и более узких). IPv4-адрес против IPv6-сети сравнивается
     * в mapped-форме ТОЛЬКО если сама сеть лежит в этом блоке: иначе обычная
     * запись `::/0` («весь IPv6») неожиданно открывала бы доступ и IPv4-клиентам,
     * то есть расширяла бы allowlist за пределы написанного.
     */
    private static function toNetworkFamily(string $address, string $network, int $prefix): ?string
    {
        $addressBytes = strlen($address);
        $networkBytes = strlen($network);

        if ($addressBytes === $networkBytes) {
            return $address;
        }

        if ($networkBytes === 4 && $addressBytes === 16) {
            if (! self::isMappedIPv4($address)) {
                return null;
            }

            return substr($address, 12);
        }

        // networkBytes === 16, addressBytes === 4.
        if ($prefix < 96 || ! self::isMappedIPv4($network)) {
            return null;
        }

        return str_repeat("\0", 10) . "\xff\xff" . $address;
    }

    /**
     * `::ffff:a.b.c.d` — первые 10 байт нули, затем `ff ff`.
     */
    private static function isMappedIPv4(string $packed): bool
    {
        return substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff";
    }

    /**
     * Сравнение по маске: побайтово, остаток маски — через битовую операцию.
     */
    private static function inNetwork(string $address, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($address, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
