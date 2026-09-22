<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Http\Controllers;

use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Cart endpoints.
 *
 * There is deliberately no `try`/`catch` and no `response()->json(['error' => …])`
 * anywhere in this controller. Every failure — a missing `session_id`, an exhausted
 * inventory item, an expired cart — is raised as an `AppError` (by the validator or by
 * `CartService`) and rendered by `ApiExceptionRenderer` into the §66 envelope. Catching
 * and re-shaping here is what produced bodies like `{"error":"session_id required"}`:
 * a flat string with no `code` to branch on and no `request_id` to correlate a support
 * ticket with a log line.
 */
class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $cart = Cart::query()
            ->where('session_id', $validated['session_id'])
            ->with(['items.inventoryItem', 'items.inventoryItem.seat'])
            ->first();

        if (!$cart) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $cart]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `bail` + `integer` are load-bearing here, not decoration. `sessions.id`
            // and `inventory_items.id` are BIGINT, so `exists:sessions,id` issues
            // `where id = 'nope'` and PostgreSQL rejects the cast with
            // SQLSTATE[22P02] "invalid syntax for type bigint" — a QueryException,
            // which means a client could turn a validation error into a 500 simply by
            // sending a string. Verified live before this change.
            //
            // `bail` is the part that actually prevents it: Laravel only stops
            // evaluating an attribute's remaining rules when `bail` is present, so
            // `integer` failing is not enough on its own — `exists` would still run.
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
            'inventory_item_id' => ['bail', 'required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        // `CartService::addItem()` declares `string $sessionId`, and this file is under
        // `strict_types=1`, so an integer `session_id` in the JSON body would be a
        // TypeError rather than a cast. The spec types `session_id` as a string, but a
        // client that sends a number must not get a 500.
        $cartItem = $this->cartService->addItem(
            (string) $validated['session_id'],
            (int) $validated['inventory_item_id'],
            (int) $validated['quantity'],
        );

        return response()->json([
            'message' => 'Item added to cart successfully',
            'data' => $cartItem,
        ], 201);
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $this->cartService->removeItem((string) $validated['session_id'], $itemId);

        return response()->json([
            'message' => 'Item removed from cart successfully',
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast — see `addItem()`.
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
        ]);

        $checkoutResult = $this->cartService->checkout((string) $validated['session_id']);

        return response()->json([
            'message' => 'Checkout successful',
            'data' => $checkoutResult,
        ]);
    }
}
