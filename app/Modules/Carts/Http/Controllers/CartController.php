<?php

declare(strict_types=1);

namespace App\Modules\Carts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Carts\Models\Cart;
use App\Modules\Carts\Http\Resources\CartResource;
use App\Modules\Carts\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService
    ) {}

    /**
     * Get or create current user cart
     */
    public function show(): JsonResponse
    {
        $cart = $this->cartService->getOrCreateForUser(auth()->user());

        return response()->json([
            'data' => new CartResource($cart)
        ]);
    }

    /**
     * Add item to cart
     */
    public function addItem(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $cart = $this->cartService->getOrCreateForUser(auth()->user());
        
        $item = $this->cartService->addItem(
            $cart,
            $request->inventory_item_id,
            $request->quantity
        );

        return response()->json([
            'data' => new CartResource($cart->fresh())
        ], 201);
    }

    /**
     * Remove item from cart
     */
    public function removeItem(Cart $cart, string $itemId): Response
    {
        $this->cartService->removeItem($cart, $itemId);

        return response(null, 204);
    }

    /**
     * Clear cart
     */
    public function clear(Cart $cart): Response
    {
        $this->cartService->clear($cart);

        return response(null, 204);
    }
}
