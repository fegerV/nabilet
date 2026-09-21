<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Http\Controllers;

use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $sessionId = $request->get('session_id');
        
        if (!$sessionId) {
            return response()->json(['error' => 'session_id required'], 422);
        }

        $cart = Cart::query()
            ->where('session_id', $sessionId)
            ->with(['items.inventoryItem', 'items.inventoryItem.seat'])
            ->first();

        if (!$cart) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $cart]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => ['required', 'exists:sessions,id'],
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $cartItem = $this->cartService->addItem(
                $request->input('session_id'),
                $request->input('inventory_item_id'),
                $request->input('quantity', 1)
            );

            return response()->json([
                'message' => 'Item added to cart successfully',
                'data' => $cartItem,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $sessionId = $request->input('session_id');

        if (!$sessionId) {
            return response()->json(['error' => 'session_id required'], 422);
        }

        try {
            $this->cartService->removeItem($sessionId, $itemId);

            return response()->json([
                'message' => 'Item removed from cart successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Cart item not found',
            ], 404);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => ['required', 'exists:sessions,id'],
        ]);

        try {
            $checkoutResult = $this->cartService->checkout($request->input('session_id'));

            return response()->json([
                'message' => 'Checkout successful',
                'data' => $checkoutResult,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
