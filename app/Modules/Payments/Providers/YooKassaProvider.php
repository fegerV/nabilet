<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YooKassa payment provider implementation.
 * 
 * @see https://yookassa.ru/developers/api
 */
class YooKassaProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly string $shopId,
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.yookassa.ru/v3'
    ) {
        if (empty($this->shopId) || empty($this->secretKey)) {
            throw new \RuntimeException('YooKassa credentials not configured');
        }
    }

    public function createPayment(array $paymentData): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Idempotence-Key' => $paymentData['idempotency_key'] ?? uniqid('yk_', true),
        ])
        ->withBasicAuth($this->shopId, $this->secretKey)
        ->post("{$this->baseUrl}/payments", [
            'amount' => [
                'value' => number_format($paymentData['amount'] / 100, 2, '.', ''),
                'currency' => $paymentData['currency'],
            ],
            'capture' => true, // Auto-capture payment
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $paymentData['success_url'] ?? config('app.url'),
                'enforce' => false,
            ],
            'description' => $paymentData['description'] ?? "Order #{$paymentData['order_id']}",
            'metadata' => [
                'order_id' => $paymentData['order_id'],
            ],
            'save_payment_method' => false,
        ]);

        if ($response->failed()) {
            Log::error('YooKassa payment creation failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to create payment with YooKassa');
        }

        $data = $response->json();

        return [
            'payment_id' => $data['id'],
            'status' => $this->mapStatus($data['status']),
            'confirmation_url' => $data['confirmation']['confirmation_url'] ?? null,
            'confirmation_token' => $data['confirmation']['confirmation_token'] ?? null,
            'provider_data' => $data,
        ];
    }

    public function getPayment(string $providerPaymentId): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
        ->withBasicAuth($this->shopId, $this->secretKey)
        ->get("{$this->baseUrl}/payments/{$providerPaymentId}");

        if ($response->failed()) {
            Log::error('YooKassa payment fetch failed', [
                'payment_id' => $providerPaymentId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to fetch payment from YooKassa');
        }

        $data = $response->json();

        return [
            'status' => $this->mapStatus($data['status']),
            'amount' => (int) round((float) $data['amount']['value'] * 100),
            'currency' => $data['amount']['currency'],
            'created_at' => $data['created_at'] ?? null,
            'provider_data' => $data,
        ];
    }

    public function refund(array $refundData): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Idempotence-Key' => uniqid('ykr_', true),
        ])
        ->withBasicAuth($this->shopId, $this->secretKey)
        ->post("{$this->baseUrl}/refunds", [
            'payment_id' => $refundData['payment_id'],
            'amount' => [
                'value' => number_format($refundData['amount'] / 100, 2, '.', ''),
                'currency' => $refundData['currency'],
            ],
            'description' => $refundData['reason'] ?? 'Refund',
        ]);

        if ($response->failed()) {
            Log::error('YooKassa refund failed', [
                'payment_id' => $refundData['payment_id'],
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to process refund with YooKassa: ' . ($response->json()['description'] ?? 'Unknown error'));
        }

        $data = $response->json();

        return [
            'refund_id' => $data['id'],
            'status' => $this->mapStatus($data['status']),
            'amount' => (int) round((float) $data['amount']['value'] * 100),
            'provider_data' => $data,
        ];
    }

    public function handleWebhook(array $payload, string $signature = ''): ?array
    {
        // YooKassa sends events via POST with event type in the payload
        // Signature verification is done via HTTPS and optional HMAC
        
        if (!isset($payload['event'], $payload['object'])) {
            Log::warning('Invalid YooKassa webhook payload', ['payload' => $payload]);
            return null;
        }

        $event = $payload['event'];
        $object = $payload['object'];

        $eventType = match ($event) {
            'payment.succeeded' => 'payment.succeeded',
            'payment.waiting_for_capture' => 'payment.waiting_for_capture',
            'payment.canceled' => 'payment.failed',
            'refund.succeeded' => 'refund.succeeded',
            'refund.failed' => 'refund.failed',
            default => null,
        };

        if ($eventType === null) {
            Log::warning('Unknown YooKassa event type', ['event' => $event]);
            return null;
        }

        return [
            'event_type' => $eventType,
            'payment_id' => $object['id'] ?? ($object['payment_id'] ?? null),
            'event_id' => $object['id'] . '_' . $event . '_' . ($object['created_at'] ?? time()),
            'timestamp' => $object['created_at'] ?? now()->toIso8601String(),
            'data' => $object,
        ];
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        // YooKassa doesn't require signature verification for webhooks by default
        // If HMAC secret is configured, verify it
        $hmacSecret = config('payments.yookassa_webhook_secret');
        
        if (empty($hmacSecret)) {
            // No secret configured, skip verification (HTTPS provides transport security)
            return true;
        }

        // Verify HMAC-SHA256 signature
        $payloadBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expectedSignature = hash_hmac('sha256', $payloadBody, $hmacSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Map YooKassa status to internal status.
     */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'pending', 'waiting_for_capture' => 'pending',
            'succeeded' => 'succeeded',
            'canceled', 'failed' => 'failed',
            default => 'pending',
        };
    }
}
