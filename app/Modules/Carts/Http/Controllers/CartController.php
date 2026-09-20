<?php

declare(strict_types=1);

namespace App\Modules\Carts\Http\Controllers;

use App\Modules\Carts\Models\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CartController extends Controller
{
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

        // TODO: Implement cart add item logic
        
        return response()->json(['message' => 'Not implemented yet'], 501);
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        // TODO: Implement cart remove item logic
        
        return response()->json(['message' => 'Not implemented yet'], 501);
    }

    public function checkout(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => ['required', 'exists:sessions,id'],
        ]);

        // TODO: Implement checkout logic
        
        return response()->json(['message' => 'Not implemented yet'], 501);
    }
}
