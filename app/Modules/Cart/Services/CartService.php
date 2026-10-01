<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Cart\Support\CartToken;
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
     * Get or create a cart for the given session, owned by the buyer token (D5).
     *
     * `$token` is mandatory: a cart without an owner would be reachable by every
     * browser sharing the session — exactly the bug D5 was introduced to fix.
     */
    public function getOrCreateCart(string $sessionId, string $token): Cart
    {
        $cart = Cart::query()
            ->where('session_id', $sessionId)
            ->where('status', 'active')
            ->where('cart_token', $token)
            ->first();

        if (!$cart) {
            $session = Session::findOrFailBySessionId($sessionId);

            $cart = Cart::create([
                'session_id' => $sessionId,
                'cart_token' => $token,
                'user_id' => $session->user_id ?? null,
                'status' => 'active',
                // C1: единый источник истины для срока холда — конфиг
                // nabilet.checkout.hold_duration_minutes (дефолт 15). UI больше
                // не считает таймер локально: expires_at отдаётся сервером в
                // каждом ответе корзины.
                'expires_at' => CarbonImmutable::now()->addMinutes($this->holdDurationMinutes()),
            ]);
        }

        return $cart;
    }

    /**
     * C1: срок удержания в минутах из конфига; HoldWindow ограничивает TTL
     * диапазоном 300–1800 секунд, поэтому выход за диапазон нормализуется.
     */
    public static function holdDurationMinutes(): int
    {
        $minutes = max(1, (int) config('nabilet.checkout.hold_duration_minutes', 15));

        return min(30, $minutes);
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
    public function addItem(string $sessionId, int $inventoryItemId, int $quantity = 1, ?string $token = null): CartItem
    {
        // D5: корзина принадлежит покупателю (токену), а не сеансу.
        // Контроллер гарантирует токен; прямой вызов службы без него —
        // ошибка вызывающего кода, а не данные клиента: ответ 422 по полю cart_token.
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        return DB::transaction(function () use ($sessionId, $inventoryItemId, $quantity, $token) {
            // Get or create cart for THIS buyer (D5)
            $cart = $this->getOrCreateCart($sessionId, $token);

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

            // A13: холд материализуется в seat_holds. Раньше записи создавал
            // только legacy-путь, и PaymentService::validateHoldsForOrder() не
            // находил ни одного холда — «защита» от оплаты после истечения была
            // мертва. Теперь у каждого холда есть запись с TTL корзины.
            \Nabilet\Modules\Orders\Models\SeatHold::create([
                'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
                'inventory_item_id' => $inventoryItemId,
                'session_id' => (int) $inventoryItem->session_id,
                'cart_id' => $cart->id,
                'quantity' => $quantity,
                'expires_at' => $cart->expires_at,
            ]);

            // Статус места: пока есть свободные — held, всё выкуплено в корзинах — sold_out.
            if ((int) $inventoryItem->available_quantity === 0) {
                $inventoryItem->update(['status' => 'sold_out']);
            } elseif ($inventoryItem->status === 'available') {
                $inventoryItem->update(['status' => 'held']);
            }

            $this->recalculateCartTotal($cart);

            return $cartItem;
        });
    }

    /**
     * Remove an item from the cart.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException cart or item missing
     */
    public function removeItem(string $sessionId, int $itemId, ?string $token = null): bool
    {
        // D5: удалить предмет можно только из корзины того же покупателя.
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        return DB::transaction(function () use ($sessionId, $itemId, $token) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->where('cart_token', $token)
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
                if ($inventoryItem->status === 'sold_out') {
                    // Есть свободные — место снова можно держать в корзинах.
                    $inventoryItem->update(['status' => 'available']);
                } elseif ($inventoryItem->status === 'held' && (int) $inventoryItem->available_quantity > 0) {
                    $inventoryItem->update(['status' => 'available']);
                }
            }

            // Снять соответствующий seat_hold (иначе sweeper вернёт quantity повторно).
            \Nabilet\Modules\Orders\Models\SeatHold::query()
                ->where('cart_id', $cart->id)
                ->where('inventory_item_id', $cartItem->inventory_item_id)
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->update(['released_at' => CarbonImmutable::now()]);

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
    public function checkout(string $sessionId, array $customer = [], ?string $token = null): array
    {
        // D5: оформить можно только корзину того же покупателя.
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        return DB::transaction(function () use ($sessionId, $customer, $token) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->where('cart_token', $token)
                ->with(['items.inventoryItem'])
                ->lockForUpdate()
                ->firstOrFail();

            // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                $cart->update(['status' => 'abandoned']);

                // A13: истёкшая корзина освобождает инвентарь немедленно —
                // иначе места зависают в held/sold до работы sweeper'а.
                $this->releaseCartInventory($cart);

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

            // Промокод пока не применяется: молча игнорировать
            // пользательский ввод хуже честного отказа — клиент увидит
            // стабильный 422 PROMO_CODE_NOT_SUPPORTED, а не счёт без скидки.
            if (!empty($customer['promo_code'])) {
                throw new DomainRuleViolation(
                    'Promo codes are not supported yet.',
                    'PROMO_CODE_NOT_SUPPORTED',
                );
            }

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            // A6 (план b): до оплаты места НЕ помечаются 'sold'. sold — это
            // подтверждённая продажа (InventoryItemStateMachine: held → sold на
            // подтверждении платежа). Промежуточное состояние — available_quantity
            // уже удержан холдами; статус остаётся held/sold_out. Это снимает
            // дефект «места сгорают при неоплате»: неоплаченные места никогда не
            // зависают в sold, а освобождаются sweeper'ом / checkout-expiry.
            foreach ($cart->items as $item) {
                if ($item->inventoryItem !== null && $item->inventoryItem->status === 'available') {
                    $item->inventoryItem->update(['status' => 'held']);
                }
            }

            // Холды корзины переживают checkout: converted_at ставится только на
            // payment.succeeded (markHoldsAsConverted). Если платёж так и не
            // прошёл, заказ заберёт orders:expire-sweeper и вернёт места.

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
                // A6-цепочка «заказ → оплата → билет»: заказ помнит корзину,
                // сеанс и событие, из которых вырос. PaymentService использует
                // orders.cart_id для валидации холдов, а TicketService берёт
                // session_id/event_id (NOT NULL в таблице tickets) именно отсюда.
                // Без этих полей билеты не выпускались никогда — проверено по коду.
                'cart_id' => $cart->id,
                'session_id' => $session?->id,
                'event_id' => $event?->id,
                'customer_email' => (string) ($customer['customer_email'] ?? ''),
                'customer_name' => $customer['customer_name'] ?? null,
                'customer_phone' => $customer['customer_phone'] ?? null,
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
    public function clearCart(string $sessionId, ?string $token = null): bool
    {
        // D5: чистим только корзину конкретного покупателя; без токена — no-op,
        // чтобы случайный вызов не снёс активные корзины всех покупателей сеанса.
        $token = CartToken::normalize($token);

        if ($token === null) {
            return false;
        }

        return DB::transaction(function () use ($sessionId, $token) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->where('cart_token', $token)
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
     * Освободить инвентарь корзины: вернуть available_quantity по каждому
     * unsold-предмету и снять активные seat_hold'ы. Идемпотентно: холды, уже
     * конвертированные (converted_at) или снятые (released_at), не трогаются,
     * поэтому повторный вызов не может «вернуть» место дважды.
     *
     * Используется checkout при CART_EXPIRED и OrderSweeper для истёкших заказов.
     */
    public function releaseCartInventory(Cart $cart): void
    {
        // Один источник истины — seat_holds: они создаются на addItem с точной
        // quantity каждого места. Возврат делаем строго по ещё не снятым холдам,
        // поэтому двойного возврата нет даже если корзина чистилась частично.
        $holds = \Nabilet\Modules\Orders\Models\SeatHold::query()
            ->where('cart_id', $cart->id)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            $inventoryItem = \Nabilet\Modules\Inventory\Models\InventoryItem::query()
                ->where('id', $hold->inventory_item_id)
                ->first();

            // Место уже оплачено (sold) — возвращать нельзя.
            if ($inventoryItem !== null && $inventoryItem->status !== 'sold') {
                $inventoryItem->increment('available_quantity', (int) $hold->quantity);

                if ((int) $inventoryItem->available_quantity >= (int) $inventoryItem->capacity) {
                    $inventoryItem->update(['status' => 'available']);
                } elseif ($inventoryItem->status === 'sold_out') {
                    $inventoryItem->update(['status' => 'held']);
                }
            }

            $hold->update(['released_at' => CarbonImmutable::now()]);
        }
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
