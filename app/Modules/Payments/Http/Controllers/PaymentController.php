<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
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
        $result = $this->paymentService->handleWebhook($provider, $request);
        
        return response()->json(['data' => $result]);
    }
}
