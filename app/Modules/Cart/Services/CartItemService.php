<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * CartItemService — позиции корзины, инвентарь и холды мест.
 *
 * Выделено из `CartService` (P2). Обязанность одна: любое изменение состава
 * корзины обязано синхронно менять три вещи —
 *   1. `cart_items` (что покупатель видит),
 *   2. `inventory_items.available_quantity` (что осталось в продаже),
 *   3. `seat_holds` (чем подтверждается удержание).
 *
 * ИНВАРИАНТ, который здесь защищается:
 *   сумма активных `seat_holds.quantity` по паре (cart_id, inventory_item_id)
 *   равна `cart_items.quantity` по той же паре.
 *
 * Его нарушение не даёт 500-й и не видно в UI. Оно даёт тихую утечку: место
 * списано из `available_quantity`, но `releaseCartInventory()` (и, значит,
 * sweeper, `StaleOrderExpirer` и checkout при истечении) возвращает места
 * строго по `seat_holds` — и не возвращает ничего за то место, которого в холде
 * нет. Место исчезает из продажи навсегда.
 *
 * Коды ответов — по `nabilet_core_spec/openapi.yaml`; см. докблок `CartService`
 * о том, почему отказы поднимаются как `AppError`, а не `\RuntimeException`.
 */
final class CartItemService
{
    public function __construct(private readonly CartService $carts)
    {
    }

    /**
     * Добавить позицию в корзину с атомарным резервированием инвентаря.
     *
     * Реализует немедленное создание холда с атомарным уменьшением, чтобы
     * исключить гонки: два покупателя не могут зарезервировать одно место
     * одновременно.
     *
     * @throws ConflictError          корзина истекла или инвентарь исчерпан
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException сеанс или позиция не найдены
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
            // Sales gate on EVERY add: the session must still be `on_sale` and inside
            // its sales window. Checking only at cart creation is not enough — a cart
            // opened while sales are live must stop accepting new seats the moment the
            // organizer flips the switch off or `sales_end_at` passes.
            $session = Session::findOrFailBySessionId($sessionId);

            if (! $session->isSellableAt()) {
                throw DomainRuleViolation::salesClosed(
                    (string) ($session->public_id ?? $session->id),
                    ['reason' => $session->salesBlockReason()]
                );
            }

            // Get or create cart for THIS buyer (D5)
            $cart = $this->carts->getOrCreateCart($sessionId, $token);

            // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                throw ConflictError::cartExpired();
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

            // A4: место обязано принадлежать сеансу корзины. Без проверки клиент
            // мог положить в корзину сеанса S место сеанса Z — холд записывался
            // на чужой сеанс, а checkout создавал заказ одного сеанса с билетами
            // другого. Отказываем чужеродный inventory_item.
            //
            // Отказ бросается ПОСЛЕ списания — это безопасно, потому что мы внутри
            // транзакции: `throw` откатит и decrement. Проверено тестом
            // `CartWritePathTest::test_add_item_rejects_a_seat_from_another_session`,
            // который сверяет `available_quantity` чужого места после отказа.
            if ((int) $inventoryItem->session_id !== (int) $sessionId) {
                throw new ConflictError(
                    'The selected seat belongs to a different session.',
                    'ITEM_SESSION_MISMATCH'
                );
            }

            // Check if item already exists in cart
            $existingItem = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('inventory_item_id', $inventoryItemId)
                ->lockForUpdate()
                ->first();

            if ($existingItem) {
                // Update quantity
                $newQuantity = $existingItem->quantity + $quantity;

                // `(string)` здесь обязателен: `InventoryItem::$casts` приводит
                // `price_amount` к integer, а `calculateTotalPrice(string $unitPrice, …)`
                // объявлен под `declare(strict_types=1)`. Без приведения повторное
                // добавление уже лежащей в корзине позиции (двойной клик, retry,
                // вторая вкладка) падало с TypeError → 500 INTERNAL_ERROR вместо
                // обновления quantity. Проверено на живом стенде.
                $existingItem->update([
                    'quantity' => $newQuantity,
                    'total_price' => $this->calculateTotalPrice((string) $inventoryItem->price_amount, $newQuantity),
                ]);

                // Холд обязан вырасти вместе с позицией — иначе инвентарь,
                // списанный этим добавлением, не вернёт ни один путь. Подробности
                // в докблоке `growHold()`.
                $this->growHold($cart, $inventoryItem, $quantity);

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

            $this->createHold($cart, $inventoryItem, $quantity);

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
     * Удалить позицию из корзины.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException корзина или позиция не найдены
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
            $inventoryItem = InventoryItem::query()
                ->where('id', $cartItem->inventory_item_id)
                ->first();

            if ($inventoryItem) {
                // A5: возвращаем ровно столько мест, сколько было в позиции. Раньше
                // при quantity>1 (например, стоячая зона qty=2) место возвращалось
                // не полностью — available_quantity занижался на (quantity-1) и
                // возникал рассинхрон с реальным числом свободных мест.
                $inventoryItem->increment('available_quantity', (int) $cartItem->quantity);
                if ($inventoryItem->status === 'sold_out') {
                    // Есть свободные — место снова можно держать в корзинах.
                    $inventoryItem->update(['status' => 'available']);
                } elseif ($inventoryItem->status === 'held' && (int) $inventoryItem->available_quantity > 0) {
                    $inventoryItem->update(['status' => 'available']);
                }
            }

            // Снять соответствующий seat_hold (иначе sweeper вернёт quantity повторно).
            SeatHold::query()
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
     * Продлить серверный холд корзины (B5).
     *
     * Сбрасывает `carts.expires_at` на `now + holdDurationMinutes()` и пролонгирует
     * все ещё активные `seat_holds` этой корзины на тот же дедлайн. Идемпотентно:
     * уже снятые (`released_at`) или конвертированные (`converted_at`) холды не
     * трогаем, поэтому повторный вызов не «вернёт» место дважды.
     *
     * Раньше кнопка «Продлить» в UI лишь перерисовывала локальный таймер — серверный
     * холд не продлевался (endpoint расширения отсутствовал). Теперь продление
     * реально отодвигает серверный `expires_at`, и UI живёт ровно до него.
     *
     * @throws ConflictError корзина не найдена, истекла, пуста или уже не active
     */
    public function extendHold(string $sessionId, ?string $token = null): array
    {
        $token = CartToken::normalize($token);

        if ($token === null || strlen($token) < 32) {
            // This endpoint is guest-only and extends a capability-owned hold;
            // require UUID-sized entropy rather than account-derived/weak tokens.
            throw ConflictError::cartExpired();
        }

        return DB::transaction(function () use ($sessionId, $token): array {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->where('cart_token', $token)
                ->lockForUpdate()
                ->first();

            $now = CarbonImmutable::now();
            // Не воскресить истёкшую корзину: sweeper мог уже вернуть места
            // в продажу. Продлевать можно только действующий hold.
            if (!$cart || $cart->expires_at === null || $cart->expires_at->lte($now)) {
                throw ConflictError::cartExpired();
            }

            if (!$cart->items()->exists()) {
                throw new ConflictError('The cart is empty.', 'CART_EMPTY');
            }

            $activeHolds = SeatHold::query()
                ->where('cart_id', $cart->id)
                ->whereNull('released_at')
                ->whereNull('converted_at')
                ->lockForUpdate()
                ->get(['id']);

            if ($activeHolds->isEmpty()) {
                throw ConflictError::cartExpired();
            }

            // Продлеваем независимо от текущего остатка — даже если до истечения
            // осталась минута, холд сбрасывается на полный срок.
            $newExpires = $now->addMinutes(CartService::holdDurationMinutes());
            $cart->update(['expires_at' => $newExpires]);

            // Пролонгируем только заблокированные активные холды: снятые при
            // отмене места или уже конвертированные в заказ трогать нельзя. В той
            // же транзакции это исключает гонку со sweeper'ом и checkout.
            SeatHold::query()
                ->whereIn('id', $activeHolds->modelKeys())
                ->update(['expires_at' => $newExpires]);

            return ['expires_at' => $newExpires->toIso8601String()];
        });
    }

    /**
     * Освободить инвентарь корзины: вернуть available_quantity по каждому
     * unsold-предмету и снять активные seat_hold'ы. Идемпотентно: холды, уже
     * конвертированные (converted_at) или снятые (released_at), не трогаются,
     * поэтому повторный вызов не может «вернуть» место дважды.
     *
     * Используется checkout при истечении корзины и `StaleOrderExpirer` для
     * истёкших заказов.
     *
     * ВАЖНО: возвращает ровно `seat_holds.quantity`. Поэтому инвариант «холд
     * покрывает позицию» из докблока класса — не косметика, а условие того, что
     * этот метод не теряет места.
     */
    public function releaseCartInventory(Cart $cart): void
    {
        // Один источник истины — seat_holds: они создаются на addItem с точной
        // quantity каждого места. Возврат делаем строго по ещё не снятым холдам,
        // поэтому двойного возврата нет даже если корзина чистилась частично.
        $holds = SeatHold::query()
            ->where('cart_id', $cart->id)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            $inventoryItem = InventoryItem::query()
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
     * Материализовать холд в `seat_holds`.
     *
     * A13: раньше записи создавал только legacy-путь, и
     * `PaymentService::validateHoldsForOrder()` не находил ни одного холда —
     * «защита» от оплаты после истечения была мертва. Теперь у каждого холда
     * есть запись с TTL корзины.
     */
    private function createHold(Cart $cart, InventoryItem $inventoryItem, int $quantity): void
    {
        SeatHold::create([
            'public_id' => (string) Str::ulid()->toBase32(),
            'inventory_item_id' => $inventoryItem->id,
            'session_id' => (int) $inventoryItem->session_id,
            'cart_id' => $cart->id,
            'quantity' => $quantity,
            'expires_at' => $cart->expires_at,
        ]);
    }

    /**
     * Довести холд до нового количества позиции.
     *
     * Зачем отдельный шаг. `seat_holds` создавался ТОЛЬКО при первой вставке
     * позиции. Повторное добавление того же места (двойной клик, retry, вторая
     * вкладка) списывало инвентарь ещё раз и увеличивало `cart_items.quantity`,
     * но холд не трогало. Инвариант «холд = позиция» нарушался, и место,
     * списанное вторым добавлением, не возвращал ни один путь освобождения:
     * `releaseCartInventory()` идёт строго по `seat_holds.quantity`.
     *
     * Воспроизведено на живом стенде (тесты
     * `CartWritePathTest::test_repeat_add_keeps_the_hold_in_step_with_the_cart_item`
     * и `test_expired_cart_returns_every_seat_it_reserved`): позиция 2 места,
     * холд 1 место, после истечения корзины `available_quantity` оставался
     * заниженным навсегда.
     */
    private function growHold(Cart $cart, InventoryItem $inventoryItem, int $quantity): void
    {
        $hold = SeatHold::query()
            ->where('cart_id', $cart->id)
            ->where('inventory_item_id', $inventoryItem->id)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->lockForUpdate()
            ->first();

        if ($hold === null) {
            // Легаси-корзина без материализованного холда (создана до A13) —
            // создаём его, чтобы инвариант восстановился, а не остался дырой.
            $this->createHold($cart, $inventoryItem, $quantity);

            return;
        }

        $hold->update(['quantity' => (int) $hold->quantity + $quantity]);
    }

    /**
     * Recalculate cart total amount.
     */
    private function recalculateCartTotal(Cart $cart): void
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
    private function calculateTotalPrice(string $unitPrice, int $quantity): string
    {
        return (string) ((int) $unitPrice * $quantity);
    }
}
