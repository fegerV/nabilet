<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Verifies webhook signatures from payment providers.
 * 
 * SECURITY CRITICAL: This class prevents forged webhooks from malicious actors
 * who might try to mark orders as paid without actual payment.
 * 
 * Supports multiple providers with different signature schemes:
 * - YooKassa: HMAC-SHA256 with optional secret
 * - Stripe: HMAC-SHA256 with timestamp
 * - Kaspi: Custom signature header
 */
class WebhookSignatureVerifier
{
    /**
     * Verify webhook signature for a given provider.
     *
     * @param string $provider Provider name (yookassa, stripe, kaspi)
     * @param array $payload Webhook payload
     * @param string $signature Signature from request header
     * @param array $headers All request headers
     * @param string|null $rawBody Сырое тело запроса — см. `body()`
     * @return bool
     * @throws DomainRuleViolation If signature verification is required but secret not configured
     */
    public function verify(
        string $provider,
        array $payload,
        string $signature,
        array $headers = [],
        ?string $rawBody = null,
    ): bool {
        $secret = $this->getProviderSecret($provider);

        if ($secret === null) {
            // For production, we REQUIRE webhook secrets to be configured
            // Returning true here would be a security vulnerability
            throw new DomainRuleViolation(
                "Webhook secret not configured for provider: {$provider}. " .
                'This is required for production security.',
                'WEBHOOK_SECRET_NOT_CONFIGURED'
            );
        }

        return match (strtolower($provider)) {
            'yookassa' => $this->verifyYooKassa($payload, $signature, $secret, $rawBody),
            'stripe' => $this->verifyStripe($payload, $signature, $headers, $secret, $rawBody),
            'kaspi' => $this->verifyKaspi($payload, $signature, $secret, $rawBody),
            default => throw new DomainRuleViolation(
                "Unknown payment provider: {$provider}",
                'UNKNOWN_PROVIDER'
            ),
        };
    }

    /**
     * Тело, по которому считается HMAC.
     *
     * Предпочитаем СЫРОЕ тело запроса: провайдер подписывает именно те байты,
     * которые отправил, а не их повторную сериализацию. Раньше подпись считалась
     * по `json_encode($request->json()->all())`, и любое расхождение канонизации
     * (порядок ключей, экранирование `/`, не-ASCII, `100.00` против `100`) ломало
     * проверку у честного отправителя и делало поведение непредсказуемым для
     * атакующего — то есть подпись перестала быть надёжным признаком
     * подлинности (аудит R-04a).
     *
     * Перекодировка оставлена только как fallback для вызовов без сырого тела
     * (внутренние вызовы, тесты), чтобы сигнатура метода не ломалась.
     */
    private function body(array $payload, ?string $rawBody): string|false
    {
        if ($rawBody !== null && $rawBody !== '') {
            return $rawBody;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Verify YooKassa webhook signature.
     * Uses HMAC-SHA256 of the JSON payload.
     */
    private function verifyYooKassa(array $payload, string $signature, string $secret, ?string $rawBody = null): bool
    {
        $payloadBody = $this->body($payload, $rawBody);

        if ($payloadBody === false) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payloadBody, $secret);

        // Constant-time comparison to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Verify Stripe webhook signature.
     * Format: t=<timestamp>,v1=<signature>
     */
    private function verifyStripe(array $payload, string $signature, array $headers, string $secret, ?string $rawBody = null): bool
    {
        // Extract timestamp from signature header
        if (!preg_match('/t=(\d+)/', $signature, $timestampMatch)) {
            return false;
        }
        
        $timestamp = (int) $timestampMatch[1];
        
        // Reject if timestamp is too old (prevent replay attacks)
        $maxAge = 300; // 5 minutes
        if (abs(time() - $timestamp) > $maxAge) {
            return false;
        }

        // Extract v1 signature
        if (!preg_match('/v1=([a-f0-9]+)/', $signature, $sigMatch)) {
            return false;
        }
        
        $providedSignature = $sigMatch[1];

        // Create signed payload (timestamp.payload)
        $payloadBody = $this->body($payload, $rawBody);

        if ($payloadBody === false) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payloadBody}";

        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Verify Kaspi webhook signature.
     * Implementation depends on Kaspi's specific requirements.
     */
    private function verifyKaspi(array $payload, string $signature, string $secret, ?string $rawBody = null): bool
    {
        // Kaspi typically uses HMAC-SHA256
        $payloadBody = $this->body($payload, $rawBody);

        if ($payloadBody === false) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payloadBody, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get provider-specific webhook secret from configuration.
     *
     * Ключи — `nabilet.payment.*`, а не `payments.*`: файла `config/payments.php`
     * в проекте нет, поэтому прежние ветки ВСЕГДА возвращали `null`. В сочетании
     * с проверкой в `WebhookAuthenticator` («секрета нет → доверяем allowlist»)
     * это означало, что заданный в `.env` секрет не подключался никогда, а
     * ошибка `WEBHOOK_SECRET_NOT_CONFIGURED` была недостижима — мёртвый код с
     * ложным чувством защиты (аудит R-04b).
     */
    private function getProviderSecret(string $provider): ?string
    {
        return match (strtolower($provider)) {
            'yookassa' => config('nabilet.payment.yookassa.webhook_secret'),
            'stripe' => config('nabilet.payment.stripe.webhook_secret'),
            'kaspi' => config('nabilet.payment.kaspi.webhook_secret'),
            default => null,
        };
    }
}
