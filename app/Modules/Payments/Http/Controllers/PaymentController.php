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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['bail', 'required', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->paymentService->initiatePayment(
            (int) $validated['order_id'],
            ['idempotency_key' => $validated['idempotency_key'] ?? null],
        );

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'confirmation_url' => $result['confirmation_url'],
        ]], 201);
    }

    public function demoPay(Request $request): JsonResponse
    {
        // Только в demo-режиме: симулятор успешной оплаты.
        if (! (bool) config('nabilet.payment.demo_mode', false)) {
            return response()->json([
                'error' => ['code' => 'DEMO_DISABLED', 'message' => 'Demo mode is off'],
            ], 403);
        }

        $paymentId = (string) $request->get('payment_id', '');

        if ($paymentId === '') {
            return response()->json([
                'error' => ['code' => 'MISSING_PAYMENT_ID', 'message' => 'payment_id is required'],
            ], 422);
        }

        $payment = $this->paymentService->confirmDemoPayment($paymentId);

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
