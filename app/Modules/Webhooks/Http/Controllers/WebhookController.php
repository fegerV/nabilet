<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\WebhookSignatureVerifier;
use Nabilet\Core\Errors\DomainRuleViolation;
use Throwable;

/**
 * WebhookController handles incoming webhooks from payment providers and other services.
 *
 * This controller is designed for shared hosting environments where webhooks must work
 * without queue workers. All processing happens synchronously.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly WebhookSignatureVerifier $signatureVerifier
    ) {}

    /**
     * Handle payment provider webhooks (YooKassa, Stripe, Kaspi, etc.)
     *
     * @param string $provider Provider name from route
     * @param Request $request Incoming webhook request
     * @return JsonResponse
     */
    public function payment(string $provider, Request $request): JsonResponse
    {
        try {
            // Get signature from headers
            $signature = $request->header('X-Webhook-Signature', '');
            if (empty($signature)) {
                // Try alternative header names for different providers
                $signature = $request->header('X-Hub-Signature-256', '');
                if (empty($signature)) {
                    $signature = $request->header('Authorization', '');
                }
            }

            // Verify signature if provider requires it
            $payload = $request->all();

            // For YooKassa and other providers that use signature verification
            if (!empty($signature) && $this->requiresSignature($provider)) {
                try {
                    $this->signatureVerifier->verify($provider, $payload, $signature, $request->headers->all());
                } catch (DomainRuleViolation $e) {
                    // Log the error but continue in development
                    if (app()->environment('production')) {
                        return response()->json([
                            'error' => 'Signature verification failed',
                            'message' => $e->getMessage(),
                        ], 403);
                    }
                    // In development, log warning but proceed
                    \Log::warning('Webhook signature verification skipped in development: ' . $e->getMessage());
                }
            }

            // Process the webhook
            $result = $this->paymentService->handleWebhook($provider, $request);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (Throwable $e) {
            \Log::error('Webhook processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
                'message' => app()->environment('production') ? 'Internal error' : $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generic webhook handler for other types of webhooks
     *
     * @param string $type Webhook type from route
     * @param Request $request Incoming webhook request
     * @return JsonResponse
     */
    public function handle(string $type, Request $request): JsonResponse
    {
        try {
            // Route to appropriate handler based on type
            return match ($type) {
                'payment' => $this->payment('generic', $request),
                default => response()->json([
                    'success' => false,
                    'error' => 'Unknown webhook type',
                    'type' => $type,
                ], 404),
            };
        } catch (Throwable $e) {
            \Log::error('Generic webhook handling failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook handling failed',
            ], 500);
        }
    }

    /**
     * Check if a provider requires signature verification
     */
    private function requiresSignature(string $provider): bool
    {
        return in_array(strtolower($provider), ['yookassa', 'stripe', 'kaspi']);
    }
}
