<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Controllers;

use Nabilet\Modules\Orders\Http\Requests\StoreOrderRequest;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'user_id', 'status']);
        $perPage = (int) $request->get('per_page', 20);
        
        $orders = $this->orderService->paginate($filters, $perPage);
        
        return response()->json([
            'data' => $orders,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'payments', 'tickets', 'user']);
        
        return response()->json(['data' => $order]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $order = $this->orderService->createOrder($data);

        return response()->json([
            'data' => $order->fresh(),
        ], 201);
    }

    public function cancel(Order $order): JsonResponse
    {
        $order = $this->orderService->cancelOrder($order);

        return response()->json(['data' => $order]);
    }
}
