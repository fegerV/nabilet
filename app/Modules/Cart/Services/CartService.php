<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Sessions\Models\Session;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

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
                'currency' => 'RUB',
                'total_amount' => '0',
                'expires_at' => CarbonImmutable::now()->addMinutes(15),
            ]);
        }

        return $cart;
    }

    /**
     * Add an item to the cart with atomic inventory reservation.
     * 
     * FIX: Implements immediate hold creation with atomic decrement to prevent race conditions.
     * Two users cannot reserve the same seat simultaneously.
     * 
     * @param string $sessionId
     * @param int $inventoryItemId
     * @param int $quantity
     * @return CartItem
     * @throws \RuntimeException If inventory is unavailable or cart is expired
     */
    public function addItem(string $sessionId, int $inventoryItemId, int $quantity = 1): CartItem
    {
        return DB::transaction(function () use ($sessionId, $inventoryItemId, $quantity) {
            // Get or create cart
            $cart = $this->getOrCreateCart($sessionId);

            // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                throw new \RuntimeException('Cart has expired');
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
                throw new \RuntimeException('Insufficient inventory available');
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
                    'total_price' => $this->calculateTotalPrice($inventoryItem->unit_price ?? '0', $newQuantity),
                ]);

                $this->recalculateCartTotal($cart);

                return $existingItem->fresh();
            }

            // Create new cart item
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'inventory_item_id' => $inventoryItemId,
                'quantity' => $quantity,
                'unit_price' => $inventoryItem->unit_price,
                'total_price' => $this->calculateTotalPrice($inventoryItem->unit_price, $quantity),
            ]);

            $this->recalculateCartTotal($cart);

            return $cartItem;
        });
    }

    /**
     * Remove an item from the cart.
     * 
     * @param string $sessionId
     * @param int $itemId
     * @return bool
     * @throws \RuntimeException If item not found or cart doesn't belong to session
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

            $this->recalculateCartTotal($cart);

            return true;
        });
    }

    /**
     * Checkout the cart - convert to order.
     * 
     * @param string $sessionId
     * @return array Returns checkout result with order info
     * @throws \RuntimeException If cart is empty or expired
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
                throw new \RuntimeException('Cart has expired');
            }

            // Check cart has items
            if ($cart->items->isEmpty()) {
                throw new \RuntimeException('Cart is empty');
            }

            // Validate all items still have available inventory
            foreach ($cart->items as $item) {
                $inventoryItem = $item->inventoryItem;
                
                if ($inventoryItem->available_quantity < $item->quantity) {
                    throw new \RuntimeException(
                        "Insufficient inventory for item {$inventoryItem->id}"
                    );
                }
            }

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            // Here we would typically create an order
            // For now, return cart data for order creation
            return [
                'cart_id' => $cart->id,
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
