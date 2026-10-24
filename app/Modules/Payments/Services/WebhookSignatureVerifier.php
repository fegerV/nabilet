<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\HmacSignature;
use Nabilet\Core\Support\SignedPayload;

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
 *
 * Само вычисление подписи и сравнение — у `HmacSignature`, сериализация
 * подписываемых байтов — у `SignedPayload`. Здесь остаётся только то, что
 * действительно различает провайдеров: откуда взять секрет и есть ли в подписи
 * метка времени.
 *
 * Раньше `verifyYooKassa()` и `verifyKaspi()` были двумя побайтово одинаковыми
 * методами: протокол у этих провайдеров один, а кода было два. Правка в одном
 * (например, переход на сырое тело) не доезжала до второго, и «Kaspi проверяется
 * иначе» было ложью докблока, а не свойством кода.
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

        if (! is_string($secret) || $secret === '') {
            // For production, we REQUIRE webhook secrets to be configured
            // Returning true here would be a security vulnerability.
            //
            // Пустая строка проверяется наравне с `null`: `hash_hmac` с пустым
            // ключом считает подпись, которую может воспроизвести кто угодно.
            // Пока проверка была только на `null`, незаполненный (но
            // существующий) ключ в `.env` давал именно это — проверка шла,
            // но проверять было нечего.
            throw new DomainRuleViolation(
                "Webhook secret not configured for provider: {$provider}. " .
                'This is required for production security.',
                'WEBHOOK_SECRET_NOT_CONFIGURED'
            );
        }

        return match (strtolower($provider)) {
            // ЮKassa и Kaspi подписывают одно и то же: hex-HMAC-SHA256 над
            // сырым телом. Одна ветка на двоих — потому что протокол один;
            // два одинаковых метода означали, что правку придётся делать дважды
            // и один из двух раз её забудут.
            'yookassa', 'kaspi' => $this->verifyHmac($payload, $signature, $secret, $rawBody),
            'stripe' => $this->verifyStripe($payload, $signature, $headers, $secret, $rawBody),
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
     * (внутренние вызовы, тесты), чтобы сигнатура метода не ломалась. Флаги
     * перекодировки — у `SignedPayload`: подписывающая сторона
     * (`SendWebhookDeliveryJob`) обязана получить те же байты, что и проверяющая
     * здесь, и совпадение больше не держится на предложении в докблоке.
     */
    private function body(array $payload, ?string $rawBody): ?string
    {
        if ($rawBody !== null && $rawBody !== '') {
            return $rawBody;
        }

        return SignedPayload::encode($payload);
    }

    /**
     * Проверка подписи провайдера, у которого схема — «hex-HMAC над телом».
     *
     * Таких провайдеров два (ЮKassa и Kaspi), и протокол у них один. Метод тоже
     * один: пока их было два, это были две копии одного правила, и любая правка
     * в одном оставляла второй с прежним поведением.
     */
    private function verifyHmac(array $payload, string $signature, string $secret, ?string $rawBody = null): bool
    {
        $payloadBody = $this->body($payload, $rawBody);

        if ($payloadBody === null) {
            return false;
        }

        return HmacSignature::verify($payloadBody, $secret, $signature);
    }

    /**
     * Verify Stripe webhook signature.
     * Format: t=<timestamp>,v1=<signature>
     *
     * Отличается от остальных провайдеров ровно одним: подписывается не тело, а
     * `"{$timestamp}.{$body}"`. Это правило Stripe, и оно живёт здесь. Само
     * вычисление и сравнение — у `HmacSignature`.
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

        if ($payloadBody === null) {
            return false;
        }

        return HmacSignature::verify("{$timestamp}.{$payloadBody}", $secret, $providedSignature);
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
