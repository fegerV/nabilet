<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Http\Middleware\AssignRequestId;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\WebhookAuthenticator;
use Throwable;

/**
 * WebhookController handles incoming webhooks from payment providers and other services.
 *
 * This controller is designed for shared hosting environments where webhooks must work
 * without queue workers. All processing happens synchronously.
 *
 * Ответы об ошибках — только конверт §66 (`{"error":{"code","message","details",
 * "request_id"}}`). Здесь были плоские строки `'error' => '…'` и
 * `'error' => $e->getMessage()`: клиент получал текст без кода, а в не-production
 * окружении — ещё и внутреннее сообщение исключения. Оба случая ловит
 * `tools/verify-error-envelope.php` (E1/E2), и оба же нарушали контракт.
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
            return $this->envelope($request, $e->errorCode, $e->getMessage(), $e->context, $e->status);
        } catch (Throwable $e) {
            // Внутреннее сообщение уходит только в лог. Ключ назван `exception`,
            // а не `error`: `error` в логе читается как «ошибка ответа» и
            // пересекается с проверкой E2, которая ищет публикацию getMessage().
            \Log::error('Webhook processing failed', [
                'provider' => $provider,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->envelope(
                $request,
                'WEBHOOK_PROCESSING_FAILED',
                'Webhook processing failed.',
                [],
                500,
            );
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
                default => $this->envelope(
                    $request,
                    'WEBHOOK_UNKNOWN_TYPE',
                    'Unknown webhook type.',
                    ['type' => $type],
                    404,
                ),
            };
        } catch (Throwable $e) {
            \Log::error('Generic webhook handling failed', [
                'type' => $type,
                'exception' => $e->getMessage(),
            ]);

            return $this->envelope(
                $request,
                'WEBHOOK_PROCESSING_FAILED',
                'Webhook handling failed.',
                [],
                500,
            );
        }
    }

    /**
     * Конверт §66. `request_id` берётся из атрибута, который ставит
     * `AssignRequestId` — иначе ручные ответы теряли корреляцию с логами,
     * которая есть у ответов, прошедших через `ApiExceptionRenderer`.
     *
     * @param  array<string, mixed>  $details
     */
    private function envelope(
        Request $request,
        string $code,
        string $message,
        array $details,
        int $status,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => (string) ($request->attributes->get(AssignRequestId::ATTRIBUTE) ?? ''),
            ],
        ], $status);
    }
}
