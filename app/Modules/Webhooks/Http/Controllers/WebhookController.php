<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\WebhookAuthenticator;
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
        private readonly WebhookAuthenticator $authenticator,
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
            // Fail-closed аутентификация: пустой allowlist + нет секрета = 422,
            // не-allowlisted IP = 403, неверная подпись = 403.
            $this->authenticator->authenticate($request, $provider);

            $result = $this->paymentService->processWebhook($provider, $request->json()->all());

            return response()->json([
                'data' => $result,
            ]);
        } catch (DomainRuleViolation $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => $e->context,
                ],
            ], $e->status);
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
}