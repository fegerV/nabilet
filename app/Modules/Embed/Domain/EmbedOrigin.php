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
}
