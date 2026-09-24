<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Sessions\Models\Session;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/**
 * Cart domain service.
 *
 * Failures are raised as `AppError` subclasses, not `\RuntimeException`. That is not
 * stylistic: the client branches on `error.code` and must never parse `error.message`
 * (see `AppError`), so a bare `\RuntimeException` leaves the caller with nothing
 * machine-readable to branch on. The controller therefore has nothing to catch — the
 * exception travels to `ApiExceptionRenderer`, which renders the §66 envelope.
 *
 * Status codes follow `nabilet_core_spec/openapi.yaml`:
 *   POST /api/v1/carts/{cart}/items  →  '409': Inventory conflict,  '422': ValidationError
 *
 * An expired cart and an exhausted inventory item are both *expected* outcomes under
 * concurrency, not bugs — hence `ConflictError` (409), which is exactly what that
 * class documents itself as being for.
 */
class CartService
{
    /**
     * Get or create a cart for the given session.
     */
    public function getOrCreateCart(string $sessionId): Cart
    {
        $cart = Cart::query()
            ->where('session_id', $sessionId)
            ->where('status', 'active')
            ->first();

        if (!$cart) {
            $session = Session::findOrFailBySessionId($sessionId);

            $cart = Cart::create([
                'session_id' => $sessionId,
                'user_id' => $session->user_id ?? null,
                'status' => 'active',
                'expires_at' => CarbonImmutable::now()->addMinutes(15),
            ]);
        }

        return $cart;
    }

    /**
     * Add an item to the cart with atomic inventory reservation.
     *
     * Implements immediate hold creation with atomic decrement to prevent race
     * conditions: two users cannot reserve the same seat simultaneously.
     *
     * @throws ConflictError          cart expired, or inventory exhausted
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException session or item missing
     */
    public function addItem(string $sessionId, int $inventoryItemId, int $quantity = 1): CartItem
    {
        return DB::transaction(function () use ($sessionId, $inventoryItemId, $quantity) {
            // Get or create cart
            $cart = $this->getOrCreateCart($sessionId);

            // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                throw self::cartExpired();
            }

            // ATOMIC INVENTORY RESERVATION - CRITICAL FIX FOR RACE CONDITION
            // Use atomic decrement with WHERE clause to ensure availability
            // This prevents two users from reserving the same seat simultaneously
            $affected = DB::table('inventory_items')
                ->where('id', $inventoryItemId)
                ->where('available_quantity', '>=', $quantity)
                ->lockForUpdate()
                ->decrement('available_quantity', $quantity);

            if ($affected === 0) {
                throw ConflictError::seatUnavailable((string) $inventoryItemId);
            }

            // Verify the inventory item still exists and get its price
            $inventoryItem = InventoryItem::query()
                ->where('id', $inventoryItemId)
                ->lockForUpdate()
                ->firstOrFail();

            // Check if item already exists in cart
            $existingItem = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('inventory_item_id', $inventoryItemId)
                ->lockForUpdate()
                ->first();

            if ($existingItem) {
                // Update quantity
                $newQuantity = $existingItem->quantity + $quantity;

                $existingItem->update([
                    'quantity' => $newQuantity,
                    'total_price' => $this->calculateTotalPrice($inventoryItem->price_amount ?? '0', $newQuantity),
                ]);

                $this->recalculateCartTotal($cart);

                return $existingItem->fresh();
            }

            // Create new cart item
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'inventory_item_id' => $inventoryItemId,
                'quantity' => $quantity,
                'unit_price' => (string) $inventoryItem->price_amount,
                'total_price' => $this->calculateTotalPrice((string) $inventoryItem->price_amount, $quantity),
            ]);

            $this->recalculateCartTotal($cart);

            return $cartItem;
        });
    }

    /**
     * Remove an item from the cart.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException cart or item missing
     */
    public function removeItem(string $sessionId, int $itemId): bool
    {
        return DB::transaction(function () use ($sessionId, $itemId) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->firstOrFail();

            $cartItem = CartItem::query()
                ->where('id', $itemId)
                ->where('cart_id', $cart->id)
                ->firstOrFail();

            $cartItem->delete();

            // Вернуть место в продажу: холд — обратимая операция.
            $inventoryItem = \Nabilet\Modules\Inventory\Models\InventoryItem::query()
                ->where('id', $cartItem->inventory_item_id)
                ->first();

            if ($inventoryItem) {
                $inventoryItem->increment('available_quantity');
                if ($inventoryItem->status === 'held') {
                    $inventoryItem->update(['status' => 'available']);
                }
            }

            $this->recalculateCartTotal($cart);

            return true;
        });
    }

    /**
     * Checkout the cart - convert to order.
     *
     * @return array Returns checkout result with order info
     * @throws ConflictError          cart expired, or inventory exhausted meanwhile
     * @throws DomainRuleViolation    cart is empty
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException cart missing
     */
    public function checkout(string $sessionId): array
    {
        return DB::transaction(function () use ($sessionId) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->with(['items.inventoryItem'])
                ->lockForUpdate()
                ->firstOrFail();

            // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                $cart->update(['status' => 'abandoned']);

                throw self::cartExpired();
            }

            // Check cart has items
            if ($cart->items->isEmpty()) {
                throw new DomainRuleViolation('Cart is empty.', 'CART_EMPTY');
            }

            // Validate all items still have available inventory.
            // addItem() already reserved (decremented) available_quantity for
            // these cart items — the seat belongs to THIS cart now. A concurrent
            // buyer physically cannot take it (atomic decrement). So we only
            // fail if the quantity somehow went negative or the item vanished.
            foreach ($cart->items as $item) {
                $inventoryItem = $item->inventoryItem;

                if ($inventoryItem === null) {
                    throw ConflictError::seatUnavailable((string) $item->inventory_item_id);
                }

                if ($inventoryItem->available_quantity < 0) {
                    throw ConflictError::seatUnavailable((string) $inventoryItem->id, [
                        'requested_quantity' => $item->quantity,
                        'available_quantity' => $inventoryItem->available_quantity,
                    ]);
                }
            }

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            // Finalize inventory: the seats this cart held are now sold.
            // available_quantity stays 0 (already decremented by addItem); we
            // flip status so they render as sold and cannot be re-held.
            foreach ($cart->items as $item) {
                if ($item->inventoryItem !== null) {
                    $item->inventoryItem->update(['status' => 'sold']);
                }
            }

            // Create the order: checkout succeeded, seats are sold, cart is
                        // converted — persist the sale so it appears in the admin orders list.
                        $session = $cart->session()->first();
                        $event = $session?->event()->first();
                        $organizationId = $event->organization_id ?? $session?->venue?->organization_id ?? 1;

                        $order = \Nabilet\Modules\Orders\Models\Order::create([
                            'organization_id' => $organizationId,
                            'user_id' => null,
                            'status' => 'pending',
                            'payment_status' => 'pending',
                            'subtotal_amount' => (int) ($cart->total_amount ?? 0),
                            'discount_amount' => 0,
                            'fee_amount' => 0,
                            'total_amount' => (int) ($cart->total_amount ?? 0),
                            'currency' => $cart->currency ?? 'RUB',
                                                        'customer_email' => '',
                                                        'customer_phone' => null,
                        ]);

                        foreach ($cart->items as $item) {
                            $inventoryItem = $item->inventoryItem;
                            \Nabilet\Modules\Orders\Models\OrderItem::create([
                                'order_id' => $order->id,
                                'inventory_item_id' => $inventoryItem?->id,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'total_amount' => $item->total_price,
                                'event_title_snapshot' => $event?->title,
                                'session_title_snapshot' => $session?->title,
                                'venue_title_snapshot' => $session?->venue?->name,
                            ]);
                        }

                        return [
                            'cart_id' => $cart->id,
                            'order_id' => $order->public_id,
                            'session_id' => $sessionId,
                            'items' => $cart->items->map(fn($item) => [
                                'inventory_item_id' => $item->inventory_item_id,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'total_price' => $item->total_price,
                            ])->toArray(),
                            'total_amount' => $cart->total_amount,
                            'currency' => $cart->currency,
                        ];
        });
    }

    /**
     * Clear all items from the cart.
     */
    public function clearCart(string $sessionId): bool
    {
        return DB::transaction(function () use ($sessionId) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->first();

            if (!$cart) {
                return false;
            }

            CartItem::where('cart_id', $cart->id)->delete();

            $cart->update([
                'total_amount' => '0',
            ]);

            return true;
        });
    }

    /**
     * An expired cart is a conflict, not a validation failure: the request was
     * well-formed and the client cannot fix it by editing a field — it must start a
     * new cart. 409 lets the frontend distinguish that from a 422 it can retry.
     */
    private static function cartExpired(): ConflictError
    {
        return new ConflictError(
            'The cart has expired. Please select your seats again.',
            'CART_EXPIRED'
        );
    }

    /**
     * Recalculate cart total amount.
     */
    protected function recalculateCartTotal(Cart $cart): void
    {
        $total = CartItem::where('cart_id', $cart->id)
            ->sum('total_price');

        $cart->update([
            'total_amount' => (string) $total,
        ]);
    }

    /**
     * Calculate total price for given unit price and quantity.
     */
    protected function calculateTotalPrice(string $unitPrice, int $quantity): string
    {
        return (string) ((int) $unitPrice * $quantity);
    }
}
