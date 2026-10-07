<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Services;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Fail-closed SSRF guard for customer-configured webhook URLs.
 *
 * A webhook URL is user input that makes the server perform an HTTP request.
 * Without validation, a tenant could probe metadata endpoints, internal admin
 * panels, or another tenant's services. Redirects are disabled by the sender;
 * otherwise a validated public URL could redirect the request to localhost.
 *
 * Hosts are checked both when a subscription is saved and immediately before
 * delivery. When cURL is available, resolved public IPs are pinned through
 * CURLOPT_RESOLVE to reduce DNS-rebinding exposure. The host name remains in the
 * URL, so TLS certificate verification still checks the configured host.
 * Deployments should additionally restrict outbound traffic at the network edge:
 * DNS pinning is not available with every HTTP handler or proxy configuration.
 */
class WebhookEndpointGuard
{
    /**
     * Resolve and validate an endpoint; return cURL entries pinning the result.
     *
     * @return list<string> CURLOPT_RESOLVE entries for each validated public DNS address
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw $this->rejected('URL вебхука должен быть HTTPS-адресом без логина, пароля и фрагмента.');
        }

        // Не нормализуем trailing dot: URL останется с точкой, а CURLOPT_RESOLVE
        // привяжет host без неё, что дало бы проверку одного имени и запрос
        // другого. Такие варианты проще отклонить, чем реконструировать URL.
        $host = strtolower((string) $parts['host']);
        $port = (int) ($parts['port'] ?? 443);

        // Только стандартный HTTPS-порт: публичный IP сам по себе не означает,
        // что на произвольном порту допустимо вызывать произвольный сервис.
        if ($host === '' || $port !== 443) {
            throw $this->rejected('Вебхук допускается только по HTTPS на стандартном порту 443.');
        }

        if ($this->isLocalHostname($host)) {
            throw $this->rejected('Вебхук не может указывать на локальный или внутренний адрес.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Literal-IP не нужен для webhook-подписок: для доменного имени
            // проще pin-ить DNS через CURLOPT_RESOLVE и сохранить TLS SNI.
            throw $this->rejected('Вебхук должен использовать доменное имя с валидным HTTPS-сертификатом, не IP-адрес.');
        }

        // Reject legacy numeric IP spellings, single-label hosts and invalid DNS.
        if (preg_match('/^[0-9.]+$/', $host)
            || ! str_contains($host, '.')
            || preg_match('/[^a-z0-9.-]/', $host)
            || str_starts_with($host, '.')
            || str_ends_with($host, '.')
            || str_contains($host, '..')) {
            throw $this->rejected('Некорректное доменное имя вебхука.');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records) || $records === []) {
            throw $this->rejected('Домен вебхука не удалось разрешить в публичный IP-адрес.');
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (! is_string($address) || $address === '') {
                continue;
            }

            if (! $this->isPublicIp($address)) {
                throw $this->rejected('Домен вебхука разрешается в локальный или зарезервированный IP-адрес.');
            }

            $addresses[] = $address;
        }

        if ($addresses === []) {
            throw $this->rejected('Домен вебхука не имеет A/AAAA-записи.');
        }

        $entries = [];

        foreach (array_unique($addresses) as $address) {
            $entries[] = sprintf(
                '%s:%d:%s',
                $host,
                $port,
                str_contains($address, ':') ? '[' . $address . ']' : $address,
            );
        }

        return $entries;
    }

    private function isLocalHostname(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.test', '.invalid', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $address): bool
    {
        // Reject IPv4-mapped IPv6: validators may classify it differently from
        // the IPv4 address embedded in the mapped form.
        if (str_starts_with(strtolower($address), '::ffff:')) {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function rejected(string $message): DomainRuleViolation
    {
        return new DomainRuleViolation(
            $message,
            'WEBHOOK_ENDPOINT_REJECTED',
            [],
            422,
        );
    }
}
