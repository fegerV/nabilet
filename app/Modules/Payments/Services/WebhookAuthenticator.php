<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Проверка входящего вебхука платежного провайдера.
 *
 * Защита в два слоя (тесты из PaymentWritePathTest жёстко это проверяют):
 *  1. allowlist IP — YooKassa не подписывает уведомления HMAC, поэтому первичная
 *     проверка — это адрес источника. Пустой allowlist + пустой секрет = «нечем
 *     проверить» → 422 WEBHOOK_NOT_AUTHENTICATED (fail-closed, а не fail-open).
 *  2. подпись — если секрет задан, дополнительно сверяем HMAC-SHA256.
 */
class WebhookAuthenticator
{
    public function __construct(
        private readonly WebhookSignatureVerifier $verifier
    ) {}

    public function authenticate(Request $request, string $provider): void
    {
        $allowlist = (array) config("nabilet.payment.{$provider}.webhook_ip_allowlist", []);
        $secret = config("nabilet.payment.{$provider}.webhook_secret");

        $ip = (string) $request->ip();

        $ipAllowed = in_array($ip, $allowlist, true) || in_array('*', $allowlist, true);
        $secretConfigured = is_string($secret) && $secret !== '';

        // Если секрет задан — главная проверка подпись; allowlist пуст — ок.
        if ($secretConfigured) {
            $signature = (string) $request->header('X-Signature', '');
            $ok = $this->verifier->verify($provider, (array) $request->json()->all(), $signature, $request->headers->all());

            if (!$ok) {
                throw new DomainRuleViolation(
                    'Webhook signature verification failed.',
                    'WEBHOOK_SIGNATURE_INVALID',
                    ['provider' => $provider],
                    403,
                );
            }

            if (!$ipAllowed && $allowlist !== []) {
                throw new DomainRuleViolation(
                    "Webhook source IP {$ip} is not in the allowlist.",
                    'WEBHOOK_IP_NOT_ALLOWED',
                    ['provider' => $provider, 'ip' => $ip],
                    403,
                );
            }

            return;
        }

        // Секрета нет — доверяем только allowlist.
        if ($allowlist === []) {
            throw new DomainRuleViolation(
                'Webhook authentication is not configured: neither an IP allowlist nor a secret.',
                'WEBHOOK_NOT_AUTHENTICATED',
                ['provider' => $provider, 'ip' => $ip],
                422,
            );
        }
        // Непустой allowlist + IP вне его = 403 (даже если секрет задан — выше
        // в ветке $secretConfigured уже сделан return, сюда попадаем только без
        // секрета).

        if (!$ipAllowed) {
            throw new DomainRuleViolation(
                "Webhook source IP {$ip} is not in the allowlist.",
                'WEBHOOK_IP_NOT_ALLOWED',
                ['provider' => $provider, 'ip' => $ip],
                403,
            );
        }
    }
}