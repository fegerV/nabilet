<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    /**
     * List user orders
     */
    public function index(): JsonResponse
    {
        $orders = Order::where('user_id', auth()->id())
            ->with(['items.inventoryItem.seat.row.sector', 'payments'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        ]);
    }

    /**
     * Get single order
     */
    public function show(string $publicId): JsonResponse
    {
        $order = Order::with([
            'items.inventoryItem.seat.row.sector',
            'payments.transactions',
            'tickets',
            'organization'
        ])
        ->where('public_id', $publicId)
        ->where('user_id', auth()->id())
        ->firstOrFail();

        return response()->json([
            'data' => new OrderResource($order)
        ]);
    }

    /**
     * Create order from cart
     */
    public function store(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'cart_id' => ['required', 'uuid', 'exists:carts,id'],
            'payment_method' => ['required', 'in:yookassa,sberpay,card,cash'],
        ]);

        $order = $this->orderService->createFromCart(
            $request->cart_id,
            auth()->user(),
            $request->payment_method
        );

        return response()->json([
            'data' => new OrderResource($order)
        ], 201);
    }

    /**
     * Get order payment status
     */
    public function payment(string $publicId): JsonResponse
    {
        $order = Order::with(['payments.latest'])
            ->where('public_id', $publicId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $payment = $order->payments->first();

        return response()->json([
            'data' => [
                'order_id' => $order->public_id,
                'status' => $payment?->status ?? 'pending',
                'amount' => $payment?->amount ?? $order->total_amount,
                'payment_url' => $payment?->payment_url,
            ]
        ]);
    }
}
