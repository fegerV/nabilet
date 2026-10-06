<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\OrderItem;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * CartCheckoutService — превращение корзины в заказ.
 *
 * Выделено из `CartService` (P2). Причина выделения не только в размере: это
 * единственная операция, которая пересекает границу модулей (Cart → Orders →
 * Sessions) и создаёт денежный документ. Всё остальное в корзине —
 * подготовительные операции, которые можно откатить, ничего не потеряв.
 */
final class CartCheckoutService
{
    public function __construct(private readonly CartItemService $items)
    {
    }

    /**
     * Оформить корзину — создать заказ и его позиции.
     *
     * @return array результат оформления с данными заказа
     * @throws ConflictError          корзина истекла или инвентарь исчерпан
     * @throws DomainRuleViolation    корзина пуста или продажи закрыты
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException корзина не найдена
     */
    public function checkout(string $sessionId, array $customer = [], ?string $token = null): array
    {
        // D5: оформить можно только корзину того же покупателя.
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        // Флаг «корзина истекла» поднимается ИЗ транзакции наружу, а не бросается
        // внутри неё. Это исправление конкретного дефекта, а не стилистика:
        // `throw` внутри `DB::transaction` откатывает транзакцию целиком — вместе
        // с только что выполненным возвратом инвентаря и пометкой `abandoned`.
        // Раньше здесь стоял `throw`, и очистка не сохранялась НИКОГДА: корзина
        // оставалась `active`, места — удержанными, а комментарий «освобождает
        // инвентарь немедленно» описывал недостижимую ветку. Воспроизведено
        // тестом CartWritePathTest::test_expired_cart_returns_every_seat_it_reserved.
        $expired = false;

        $result = DB::transaction(function () use ($sessionId, $customer, $token, &$expired): ?array {
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
                $this->items->releaseCartInventory($cart);

                $expired = true;

                return null;
            }

            // Check cart has items
            if ($cart->items->isEmpty()) {
                throw new DomainRuleViolation('Cart is empty.', 'CART_EMPTY');
            }

            // Sales gate at checkout: seats may still sit in a cart that was filled
            // while sales were live, but the order itself can only be placed while
            // the session is `on_sale` and inside its sales window. Existing holds
            // are kept (the sweeper releases them on TTL), so the buyer can retry
            // if the organizer reopens sales before the hold expires.
            $session = Session::findOrFailBySessionId($sessionId);

            if (! $session->isSellableAt()) {
                throw DomainRuleViolation::salesClosed(
                    (string) ($session->public_id ?? $session->id),
                    ['reason' => $session->salesBlockReason()]
                );
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

            $order = Order::create([
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
                OrderItem::create([
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

        // Возврат инвентаря уже зафиксирован транзакцией — только теперь можно
        // отказать, не потеряв очистку.
        if ($expired) {
            throw ConflictError::cartExpired();
        }

        return $result ?? [];
    }
}
