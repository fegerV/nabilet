<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Domain;

/**
 * The browser origin an embed request came from, and whether it is allowed.
 *
 * This is the whole of ТЗ §18's "check Origin" rule, and it is written as a pure
 * class because it is exactly the kind of logic that is easy to get subtly wrong
 * and hard to notice afterwards: a comparison that is case-sensitive rejects a
 * site that works, and one that ignores a port, or widens a host to its
 * subdomains, accepts a site the organizer never listed. Neither failure produces
 * an error — one produces a support ticket, the other produces silence.
 *
 * No Laravel here on purpose: `tools/verify-purity.php` loads this directory
 * without a framework, so the rule is testable without a database.
 */
final class EmbedOrigin
{
    /**
     * Extract the host from an `Origin` (or `Referer`) header.
     *
     * Returns `null` when the header is absent, unparsable, or not an absolute
     * http(s) origin. `null` is the only value callers may read as "unknown", and
     * unknown is refused rather than allowed.
     *
     * The scheme and the port are dropped deliberately: the allow-list is a list
     * of *sites*, and `https://example.com` and `https://example.com:8443` are the
     * same site. `Origin: null` — a sandboxed iframe, a `data:` document — parses
     * to nothing and is therefore refused, which is right: such a document has no
     * site the organizer could have listed.
     */
    public static function hostFrom(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || $raw === 'null') {
            return null;
        }

        $parts = parse_url($raw);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        return $host === '' ? null : $host;
    }

    /**
     * Is `$host` covered by `$domains`?
     *
     * Two entry forms are accepted, and the difference is the point:
     *
     *   `example.com`   — that host, exactly;
     *   `.example.com`  — that host AND any subdomain of it.
     *
     * A bare `example.com` deliberately does NOT match `www.example.com`. A
     * subdomain is a different origin and is frequently operated by somebody else,
     * so widening a bare entry to its subdomains is the kind of convenience that
     * quietly turns one listed site into an open redirect target. An organizer who
     * wants subdomains writes the leading dot and means it.
     *
     * @param  list<string>  $domains
     */
    public static function isAllowed(string $host, array $domains): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));

            if ($domain === '') {
                continue;
            }

            if (str_starts_with($domain, '.')) {
                // `.example.com` covers `example.com` itself and any subdomain.
                if ($host === substr($domain, 1) || str_ends_with($host, $domain)) {
                    return true;
                }

                continue;
            }

            if ($host === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Привести введённый организатором домен к той форме, которую понимает
     * `isAllowed()`, или отказать.
     *
     * ЗАЧЕМ ЭТО ЗДЕСЬ, А НЕ В КОНТРОЛЛЕРЕ. «Что считается внесённым сайтом» и
     * «покрыт ли этот сайт списком» — одна и та же единица измерения. Записанная
     * в контроллере, она разошлась бы с чтением молча: `https://partner.example`
     * лёг бы в колонку как есть и не совпал бы НИКОГДА, потому что `hostFrom()`
     * отдаёт голый хост в нижнем регистре. Ошибки при этом не возникает —
     * виджет просто получает 403 на разрешённом сайте, и искать причину
     * пришлось бы в браузере.
     *
     * ЧТО ПРИНИМАЕТСЯ. Голый хост (`example.com`, `localhost`, `127.0.0.1`) и
     * полный адрес (`https://example.com/afisha`) — организатор копирует из
     * адресной строки. Ведущая точка сохраняется: она означает «и поддомены», и
     * терять её нельзя.
     *
     * ЧТО ОТВЕРГАЕТСЯ. Пустое значение и всё, что не является именем хоста.
     * `null` означает «это не сайт», и вызывающий обязан превратить его в 422, а
     * не записать в колонку.
     */
    public static function normalizeDomain(?string $raw): ?string
    {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            return null;
        }

        $allowSubdomains = str_starts_with($value, '.');

        if ($allowSubdomains) {
            $value = substr($value, 1);
        }

        if ($value === '') {
            return null;
        }

        // Полный адрес разбирается тем же `hostFrom()`, что и заголовок Origin:
        // второй способ понимать «где живёт сайт» здесь был бы второй копией
        // правила. Схема добавляется только чтобы `parse_url` увидел хост, —
        // в колонку попадает уже голое имя.
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~', $value) === 1) {
            $value = (string) self::hostFrom($value);
        } elseif (strpbrk($value, '/:@') !== false) {
            $value = (string) self::hostFrom('https://' . $value);
        }

        if ($value === '' || self::isHostName($value) === false) {
            return null;
        }

        return $allowSubdomains ? '.' . $value : $value;
    }

    /**
     * Похоже ли значение на имя хоста: метки из `[a-z0-9-]`, разделённые точками.
     *
     * Одна метка допустима намеренно: `localhost` и `127.0.0.1` — реальные
     * адреса, на которых виджет отлаживают, и запрещать их значило бы требовать
     * от разработчика поднимать DNS ради проверки.
     */
    private static function isHostName(string $host): bool
    {
        return preg_match(
            '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/',
            $host
        ) === 1;
    }
}
