<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Providers;

/**
 * Interface for payment providers.
 * 
 * All payment gateway implementations must follow this contract
 * to ensure consistent payment processing across different providers.
 */
interface PaymentProviderInterface
{
    /**
     * Create a new payment.
     *
     * @param array{
     *     amount: int,
     *     currency: string,
     *     order_id: int,
     *     description: string,
     *     customer_email?: string,
     *     success_url?: string,
     *     failure_url?: string
     * } $paymentData
     * @return array{
     *     payment_id: string,
     *     status: string,
     *     confirmation_url?: string,
     *     confirmation_token?: string,
     *     provider_data: array
     * }
     */
    public function createPayment(array $paymentData): array;

    /**
     * Get payment status from provider.
     *
     * @param string $providerPaymentId
     * @return array{
     *     status: string,
     *     amount?: int,
     *     currency?: string,
     *     created_at?: string,
     *     provider_data: array
     * }
     */
    public function getPayment(string $providerPaymentId): array;

    /**
     * Process refund.
     *
     * @param array{
     *     payment_id: string,
     *     amount: int,
     *     currency: string,
     *     reason?: string
     * } $refundData
     * @return array{
     *     refund_id: string,
     *     status: string,
     *     amount: int,
     *     provider_data: array
     * }
     */
    public function refund(array $refundData): array;

    /**
     * Handle incoming webhook payload.
     *
     * @param array $payload Raw webhook data
     * @param string $signature Webhook signature for verification
     * @return array{
     *     event_type: string,
     *     payment_id: string,
     *     event_id: string,
     *     timestamp: string,
     *     data: array
     * }|null Returns null if webhook is invalid or signature verification fails
     */
    public function handleWebhook(array $payload, string $signature = ''): ?array;

    /**
     * Verify webhook signature.
     *
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifySignature(array $payload, string $signature): bool;
}
