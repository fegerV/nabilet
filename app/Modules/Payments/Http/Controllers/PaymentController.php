<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Http\Controllers;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\Services\WebhookAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['order_id', 'status', 'provider']);
        $perPage = (int) $request->get('per_page', 20);
        
        $payments = $this->paymentService->paginate($filters, $perPage);
        
        return response()->json([
            'data' => $payments,
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['order', 'transactions', 'refunds']);
        
        return response()->json(['data' => $payment]);
    }

    public function webhook(string $provider, Request $request): JsonResponse
        {
            try {
                // НЕ-проверка здесь = приём подделанных уведомлений. Fail-closed.
                app(WebhookAuthenticator::class)->authenticate($request, $provider);
            } catch (DomainRuleViolation $e) {
                // В тестах APP_DEBUG=true, но рендер через ApiExceptionRenderer всё
                // равно может вернуть null на не-API-путях. Гарантированно отвечаем
                // сами, чтобы status/code дошли до клиента.
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                        'details' => $e->context,
                    ],
                ], $e->status);
            }

            $result = $this->paymentService->processWebhook($provider, $request->json()->all());

            return response()->json(['data' => $result]);
        }
}
