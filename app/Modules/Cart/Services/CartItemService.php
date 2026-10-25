<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Cart\Domain\CartStatus;
use Nabilet\Modules\Cart\Support\DbRetry;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * CartItemService вЂ” РїРѕР·РёС†РёРё РєРѕСЂР·РёРЅС‹, РёРЅРІРµРЅС‚Р°СЂСЊ Рё С…РѕР»РґС‹ РјРµСЃС‚.
 *
 * Р’С‹РґРµР»РµРЅРѕ РёР· `CartService` (P2). РћР±СЏР·Р°РЅРЅРѕСЃС‚СЊ РѕРґРЅР°: Р»СЋР±РѕРµ РёР·РјРµРЅРµРЅРёРµ СЃРѕСЃС‚Р°РІР°
 * РєРѕСЂР·РёРЅС‹ РѕР±СЏР·Р°РЅРѕ СЃРёРЅС…СЂРѕРЅРЅРѕ РјРµРЅСЏС‚СЊ С‚СЂРё РІРµС‰Рё вЂ”
 *   1. `cart_items` (С‡С‚Рѕ РїРѕРєСѓРїР°С‚РµР»СЊ РІРёРґРёС‚),
 *   2. `inventory_items.available_quantity` (С‡С‚Рѕ РѕСЃС‚Р°Р»РѕСЃСЊ РІ РїСЂРѕРґР°Р¶Рµ),
 *   3. `seat_holds` (С‡РµРј РїРѕРґС‚РІРµСЂР¶РґР°РµС‚СЃСЏ СѓРґРµСЂР¶Р°РЅРёРµ).
 *
 * РРќР’РђР РРђРќРў, РєРѕС‚РѕСЂС‹Р№ Р·РґРµСЃСЊ Р·Р°С‰РёС‰Р°РµС‚СЃСЏ:
 *   СЃСѓРјРјР° Р°РєС‚РёРІРЅС‹С… `seat_holds.quantity` РїРѕ РїР°СЂРµ (cart_id, inventory_item_id)
 *   СЂР°РІРЅР° `cart_items.quantity` РїРѕ С‚РѕР№ Р¶Рµ РїР°СЂРµ.
 *
 * Р•РіРѕ РЅР°СЂСѓС€РµРЅРёРµ РЅРµ РґР°С‘С‚ 500-Р№ Рё РЅРµ РІРёРґРЅРѕ РІ UI. РћРЅРѕ РґР°С‘С‚ С‚РёС…СѓСЋ СѓС‚РµС‡РєСѓ: РјРµСЃС‚Рѕ
 * СЃРїРёСЃР°РЅРѕ РёР· `available_quantity`, РЅРѕ `releaseCartInventory()` (Рё, Р·РЅР°С‡РёС‚,
 * sweeper, `StaleOrderExpirer` Рё checkout РїСЂРё РёСЃС‚РµС‡РµРЅРёРё) РІРѕР·РІСЂР°С‰Р°РµС‚ РјРµСЃС‚Р°
 * СЃС‚СЂРѕРіРѕ РїРѕ `seat_holds` вЂ” Рё РЅРµ РІРѕР·РІСЂР°С‰Р°РµС‚ РЅРёС‡РµРіРѕ Р·Р° С‚Рѕ РјРµСЃС‚Рѕ, РєРѕС‚РѕСЂРѕРіРѕ РІ С…РѕР»РґРµ
 * РЅРµС‚. РњРµСЃС‚Рѕ РёСЃС‡РµР·Р°РµС‚ РёР· РїСЂРѕРґР°Р¶Рё РЅР°РІСЃРµРіРґР°.
 *
 * РљРѕРґС‹ РѕС‚РІРµС‚РѕРІ вЂ” РїРѕ `nabilet_core_spec/openapi.yaml`; СЃРј. РґРѕРєР±Р»РѕРє `CartService`
 * Рѕ С‚РѕРј, РїРѕС‡РµРјСѓ РѕС‚РєР°Р·С‹ РїРѕРґРЅРёРјР°СЋС‚СЃСЏ РєР°Рє `AppError`, Р° РЅРµ `\RuntimeException`.
 */
final class CartItemService
{
    /**
     * `$loyaltySettings` вЂ” СЂР°РЅРЅРёР№ РґРѕСЃС‚СѓРї РєР»СѓР±Р° (С„Р°Р·Р° 3b): РіРµР№С‚ РїСЂРѕРґР°Р¶ РЅР° РєР°Р¶РґРѕРј
     * РґРѕР±Р°РІР»РµРЅРёРё РїРѕР·РёС†РёРё СЃРїСЂР°С€РёРІР°РµС‚, РЅР° СЃРєРѕР»СЊРєРѕ С‡Р°СЃРѕРІ РѕС‚РєСЂС‹Р»РѕСЃСЊ РѕРєРЅРѕ РґР»СЏ СЌС‚РѕРіРѕ
     * РїРѕРєСѓРїР°С‚РµР»СЏ. Р—РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ вЂ” С‚РѕС‚ Р¶Рµ РїСЂРёС‘Рј, С‡С‚Рѕ Сѓ `BonusService`
     * РІ `CartCheckoutService`: РєРѕРЅС‚РµР№РЅРµСЂ РїРѕРґСЃС‚Р°РІР»СЏРµС‚ СЃРІРѕР№, `new` СЂР°Р±РѕС‚Р°РµС‚.
     */
    public function __construct(
        private readonly CartService $carts,
        private readonly LoyaltySettingsService $loyaltySettings = new LoyaltySettingsService(),
    ) {
    }

    /**
     * Р”РѕР±Р°РІРёС‚СЊ РїРѕР·РёС†РёСЋ РІ РєРѕСЂР·РёРЅСѓ СЃ Р°С‚РѕРјР°СЂРЅС‹Рј СЂРµР·РµСЂРІРёСЂРѕРІР°РЅРёРµРј РёРЅРІРµРЅС‚Р°СЂСЏ.
     *
     * Р РµР°Р»РёР·СѓРµС‚ РЅРµРјРµРґР»РµРЅРЅРѕРµ СЃРѕР·РґР°РЅРёРµ С…РѕР»РґР° СЃ Р°С‚РѕРјР°СЂРЅС‹Рј СѓРјРµРЅСЊС€РµРЅРёРµРј, С‡С‚РѕР±С‹
     * РёСЃРєР»СЋС‡РёС‚СЊ РіРѕРЅРєРё: РґРІР° РїРѕРєСѓРїР°С‚РµР»СЏ РЅРµ РјРѕРіСѓС‚ Р·Р°СЂРµР·РµСЂРІРёСЂРѕРІР°С‚СЊ РѕРґРЅРѕ РјРµСЃС‚Рѕ
     * РѕРґРЅРѕРІСЂРµРјРµРЅРЅРѕ.
     *
     * @param User|null $buyer РІРѕС€РµРґС€РёР№ РїРѕРєСѓРїР°С‚РµР»СЊ (РґР»СЏ СЂР°РЅРЅРµРіРѕ РґРѕСЃС‚СѓРїР° РєР»СѓР±Р°, С„Р°Р·Р° 3b)
     * @param string|null $token С‚РѕРєРµРЅ РїРѕРєСѓРїР°С‚РµР»СЏ (X-Cart-Token)
     * @throws ConflictError          РєРѕСЂР·РёРЅР° РёСЃС‚РµРєР»Р° РёР»Рё РёРЅРІРµРЅС‚Р°СЂСЊ РёСЃС‡РµСЂРїР°РЅ
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException СЃРµР°РЅСЃ РёР»Рё РїРѕР·РёС†РёСЏ РЅРµ РЅР°Р№РґРµРЅС‹
     */
    public function addItem(string $sessionId, int $inventoryItemId, int $quantity = 1, ?string $token = null, ?User $buyer = null): CartItem
    {
        // D5: РєРѕСЂР·РёРЅР° РїСЂРёРЅР°РґР»РµР¶РёС‚ РїРѕРєСѓРїР°С‚РµР»СЋ (С‚РѕРєРµРЅСѓ), Р° РЅРµ СЃРµР°РЅСЃСѓ.
        // РљРѕРЅС‚СЂРѕР»Р»РµСЂ РіР°СЂР°РЅС‚РёСЂСѓРµС‚ С‚РѕРєРµРЅ; РїСЂСЏРјРѕР№ РІС‹Р·РѕРІ СЃР»СѓР¶Р±С‹ Р±РµР· РЅРµРіРѕ вЂ”
        // РѕС€РёР±РєР° РІС‹Р·С‹РІР°СЋС‰РµРіРѕ РєРѕРґР°, Р° РЅРµ РґР°РЅРЅС‹Рµ РєР»РёРµРЅС‚Р°: РѕС‚РІРµС‚ 422 РїРѕ РїРѕР»СЋ cart_token.
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        // DbRetry (Р°СѓРґРёС‚ 1.3/D4-Р°СЂС…): deadlock 1213/40001 РЅР° РїСѓС‚Рё РґРѕР±Р°РІР»РµРЅРёСЏ
        // РјРµСЃС‚Р° СЂР°РЅСЊС€Рµ СѓС…РѕРґРёР» РєР»РёРµРЅС‚Сѓ 500-РµР№. РўСЂР°РЅР·Р°РєС†РёСЏ all-or-nothing вЂ”
        // РїРѕРІС‚РѕСЂ Р±РµР·РѕРїР°СЃРµРЅ; Сѓ РїР»Р°С‚С‘Р¶РЅС‹С… РІС‹Р·РѕРІРѕРІ РёРґРµРјРїРѕС‚РµРЅС‚РЅРѕСЃС‚СЊ СЃРІРѕСЏ.
        return DbRetry::run(fn (): CartItem => DB::transaction(function () use ($sessionId, $inventoryItemId, $quantity, $token, $buyer) {
            // Sales gate on EVERY add: the session must still be `on_sale` and inside
            // its sales window. Checking only at cart creation is not enough вЂ” a cart
            // opened while sales are live must stop accepting new seats the moment the
            // organizer flips the switch off or `sales_end_at` passes.
            //
            // Р¤Р°Р·Р° 3b: РєР»СѓР±РЅС‹Р№ РїРѕРєСѓРїР°С‚РµР»СЊ РІ РѕРєРЅРµ СЂР°РЅРЅРµРіРѕ РґРѕСЃС‚СѓРїР° РґРѕР±Р°РІР»СЏРµС‚ РјРµСЃС‚Р°
            // Р”Рћ РїСѓР±Р»РёС‡РЅРѕРіРѕ СЃС‚Р°СЂС‚Р° вЂ” С‚РѕС‚ Р¶Рµ СЃРґРІРёРі РѕРєРЅР°, С‡С‚Рѕ Рё РїСЂРё СЃРѕР·РґР°РЅРёРё РєРѕСЂР·РёРЅС‹.
            $earlyAccessHours = $this->loyaltySettings->earlyAccessHoursForUser($buyer);

            $session = Session::findOrFailBySessionId($sessionId);

            if (! $session->isSellableAt(null, $earlyAccessHours)) {
                throw DomainRuleViolation::salesClosed(
                    (string) ($session->public_id ?? $session->id),
                    ['reason' => $session->salesBlockReason(null, $earlyAccessHours)]
                );
            }

            // Get or create cart for THIS buyer (D5)
            $cart = $this->carts->getOrCreateCart($sessionId, $token, $buyer);

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

            // A4: РјРµСЃС‚Рѕ РѕР±СЏР·Р°РЅРѕ РїСЂРёРЅР°РґР»РµР¶Р°С‚СЊ СЃРµР°РЅСЃСѓ РєРѕСЂР·РёРЅС‹. Р‘РµР· РїСЂРѕРІРµСЂРєРё РєР»РёРµРЅС‚
            // РјРѕРі РїРѕР»РѕР¶РёС‚СЊ РІ РєРѕСЂР·РёРЅСѓ СЃРµР°РЅСЃР° S РјРµСЃС‚Рѕ СЃРµР°РЅСЃР° Z вЂ” С…РѕР»Рґ Р·Р°РїРёСЃС‹РІР°Р»СЃСЏ
            // РЅР° С‡СѓР¶РѕР№ СЃРµР°РЅСЃ, Р° checkout СЃРѕР·РґР°РІР°Р» Р·Р°РєР°Р· РѕРґРЅРѕРіРѕ СЃРµР°РЅСЃР° СЃ Р±РёР»РµС‚Р°РјРё
            // РґСЂСѓРіРѕРіРѕ. РћС‚РєР°Р·С‹РІР°РµРј С‡СѓР¶РµСЂРѕРґРЅС‹Р№ inventory_item.
            //
            // РћС‚РєР°Р· Р±СЂРѕСЃР°РµС‚СЃСЏ РџРћРЎР›Р• СЃРїРёСЃР°РЅРёСЏ вЂ” СЌС‚Рѕ Р±РµР·РѕРїР°СЃРЅРѕ, РїРѕС‚РѕРјСѓ С‡С‚Рѕ РјС‹ РІРЅСѓС‚СЂРё
            // С‚СЂР°РЅР·Р°РєС†РёРё: `throw` РѕС‚РєР°С‚РёС‚ Рё decrement. РџСЂРѕРІРµСЂРµРЅРѕ С‚РµСЃС‚РѕРј
            // `CartWritePathTest::test_add_item_rejects_a_seat_from_another_session`,
            // РєРѕС‚РѕСЂС‹Р№ СЃРІРµСЂСЏРµС‚ `available_quantity` С‡СѓР¶РѕРіРѕ РјРµСЃС‚Р° РїРѕСЃР»Рµ РѕС‚РєР°Р·Р°.
            if ((int) $inventoryItem->session_id !== (int) $sessionId) {
                throw new ConflictError(
                    'The selected seat belongs to a different session.',
                    'ITEM_SESSION_MISMATCH'
                );
            }

            // Р’Р°Р»СЋС‚РЅР°СЏ СЃРІРµСЂРєР° (Р°СѓРґРёС‚ Р°СЂС…РёС‚РµРєС‚СѓСЂС‹ D7): Сѓ `carts` РЅРµС‚ РєРѕР»РѕРЅРєРё
            // РІР°Р»СЋС‚С‹ вЂ” СЌС‚Р°Р»РѕРЅ РІС‹РІРѕРґРёРј РёР· РІР°Р»СЋС‚ РёРЅРІРµРЅС‚Р°СЂСЏ СѓР¶Рµ Р»РµР¶Р°С‰РёС… РїРѕР·РёС†РёР№.
            // Р¦РµРЅР° РєР°Р¶РґРѕРіРѕ inventory_item вЂ” СЃРѕС…СЂР°РЅС‘РЅРЅС‹Р№ snapshot СЃРІРѕРµРіРѕ СЃРµР°РЅСЃР°,
            // Рё СЃРјРµС€РµРЅРёРµ RUB-РјРµСЃС‚Р° СЃ KZT-РєРѕСЂР·РёРЅРѕР№ РґР°Р»Рѕ Р±С‹ СЃРјРµС€Р°РЅРЅС‹Р№ total Р±РµР·
            // РїРµСЂРµСЃС‡С‘С‚Р°. Р‘СЂРѕСЃРѕРє РІРЅСѓС‚СЂРё С‚СЂР°РЅР·Р°РєС†РёРё РѕС‚РєР°С‚С‹РІР°РµС‚ decrement РІС‹С€Рµ.
            $itemCurrency = (string) ($inventoryItem->currency ?? 'RUB');

            $cartCurrencies = DB::table('cart_items')
                ->join('inventory_items', 'inventory_items.id', '=', 'cart_items.inventory_item_id')
                ->where('cart_items.cart_id', $cart->id)
                ->lockForUpdate()
                ->distinct()
                ->pluck('inventory_items.currency');

            if ($cartCurrencies->isNotEmpty() && ! $cartCurrencies->contains($itemCurrency)) {
                throw new ConflictError(
                    sprintf(
                        'The seat is in %s, but the cart already contains %s items.',
                        $itemCurrency,
                        $cartCurrencies->implode(', ')
                    ),
                    'CURRENCY_MISMATCH'
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

                // `(string)` Р·РґРµСЃСЊ РѕР±СЏР·Р°С‚РµР»РµРЅ: `InventoryItem::$casts` РїСЂРёРІРѕРґРёС‚
                // `price_amount` Рє integer, Р° `calculateTotalPrice(string $unitPrice, вЂ¦)`
                // РѕР±СЉСЏРІР»РµРЅ РїРѕРґ `declare(strict_types=1)`. Р‘РµР· РїСЂРёРІРµРґРµРЅРёСЏ РїРѕРІС‚РѕСЂРЅРѕРµ
                // РґРѕР±Р°РІР»РµРЅРёРµ СѓР¶Рµ Р»РµР¶Р°С‰РµР№ РІ РєРѕСЂР·РёРЅРµ РїРѕР·РёС†РёРё (РґРІРѕР№РЅРѕР№ РєР»РёРє, retry,
                // РІС‚РѕСЂР°СЏ РІРєР»Р°РґРєР°) РїР°РґР°Р»Рѕ СЃ TypeError в†’ 500 INTERNAL_ERROR РІРјРµСЃС‚Рѕ
                // РѕР±РЅРѕРІР»РµРЅРёСЏ quantity. РџСЂРѕРІРµСЂРµРЅРѕ РЅР° Р¶РёРІРѕРј СЃС‚РµРЅРґРµ.
                $existingItem->update([
                    'quantity' => $newQuantity,
                    'total_price' => $this->calculateTotalPrice((string) $inventoryItem->price_amount, $newQuantity),
                ]);

                // РҐРѕР»Рґ РѕР±СЏР·Р°РЅ РІС‹СЂР°СЃС‚Рё РІРјРµСЃС‚Рµ СЃ РїРѕР·РёС†РёРµР№ вЂ” РёРЅР°С‡Рµ РёРЅРІРµРЅС‚Р°СЂСЊ,
                // СЃРїРёСЃР°РЅРЅС‹Р№ СЌС‚РёРј РґРѕР±Р°РІР»РµРЅРёРµРј, РЅРµ РІРµСЂРЅС‘С‚ РЅРё РѕРґРёРЅ РїСѓС‚СЊ. РџРѕРґСЂРѕР±РЅРѕСЃС‚Рё
                // РІ РґРѕРєР±Р»РѕРєРµ `growHold()`.
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

            // РЎС‚Р°С‚СѓСЃ РјРµСЃС‚Р°: РїРѕРєР° РµСЃС‚СЊ СЃРІРѕР±РѕРґРЅС‹Рµ вЂ” held, РІСЃС‘ РІС‹РєСѓРїР»РµРЅРѕ РІ РєРѕСЂР·РёРЅР°С… вЂ” sold_out.
            if ((int) $inventoryItem->available_quantity === 0) {
                $inventoryItem->update(['status' => InventoryItemStateMachine::SOLD_OUT]);
            } elseif ($inventoryItem->status === InventoryItemStateMachine::AVAILABLE) {
                $inventoryItem->update(['status' => InventoryItemStateMachine::HELD]);
            }

            $this->recalculateCartTotal($cart);

            return $cartItem;
        }));
    }

    /**
     * РЈРґР°Р»РёС‚СЊ РїРѕР·РёС†РёСЋ РёР· РєРѕСЂР·РёРЅС‹.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException РєРѕСЂР·РёРЅР° РёР»Рё РїРѕР·РёС†РёСЏ РЅРµ РЅР°Р№РґРµРЅР°
     */
    public function removeItem(string $sessionId, int $itemId, ?string $token = null): bool
    {
        // D5: СѓРґР°Р»РёС‚СЊ РїСЂРµРґРјРµС‚ РјРѕР¶РЅРѕ С‚РѕР»СЊРєРѕ РёР· РєРѕСЂР·РёРЅС‹ С‚РѕРіРѕ Р¶Рµ РїРѕРєСѓРїР°С‚РµР»СЏ.
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        return DB::transaction(function () use ($sessionId, $itemId, $token) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', CartStatus::ACTIVE)
                ->where('cart_token', $token)
                ->firstOrFail();

            $cartItem = CartItem::query()
                ->where('id', $itemId)
                ->where('cart_id', $cart->id)
                ->firstOrFail();

            $cartItem->delete();

            // Р’РµСЂРЅСѓС‚СЊ РјРµСЃС‚Рѕ РІ РїСЂРѕРґР°Р¶Сѓ: С…РѕР»Рґ вЂ” РѕР±СЂР°С‚РёРјР°СЏ РѕРїРµСЂР°С†РёСЏ.
            $inventoryItem = InventoryItem::query()
                ->where('id', $cartItem->inventory_item_id)
                ->first();

            if ($inventoryItem) {
                // A5: РІРѕР·РІСЂР°С‰Р°РµРј СЂРѕРІРЅРѕ СЃС‚РѕР»СЊРєРѕ РјРµСЃС‚, СЃРєРѕР»СЊРєРѕ Р±С‹Р»Рѕ РІ РїРѕР·РёС†РёРё. Р Р°РЅСЊС€Рµ
                // РїСЂРё quantity>1 (РЅР°РїСЂРёРјРµСЂ, СЃС‚РѕСЏС‡Р°СЏ Р·РѕРЅР° qty=2) РјРµСЃС‚Рѕ РІРѕР·РІСЂР°С‰Р°Р»РѕСЃСЊ
                // РЅРµ РїРѕР»РЅРѕСЃС‚СЊСЋ вЂ” available_quantity Р·Р°РЅРёР¶Р°Р»СЃСЏ РЅР° (quantity-1) Рё
                // РІРѕР·РЅРёРєР°Р» СЂР°СЃСЃРёРЅС…СЂРѕРЅ СЃ СЂРµР°Р»СЊРЅС‹Рј С‡РёСЃР»РѕРј СЃРІРѕР±РѕРґРЅС‹С… РјРµСЃС‚.
                $inventoryItem->increment('available_quantity', (int) $cartItem->quantity);
                if ($inventoryItem->status === InventoryItemStateMachine::SOLD_OUT) {
                    // Р•СЃС‚СЊ СЃРІРѕР±РѕРґРЅС‹Рµ вЂ” РјРµСЃС‚Рѕ СЃРЅРѕРІР° РјРѕР¶РЅРѕ РґРµСЂР¶Р°С‚СЊ РІ РєРѕСЂР·РёРЅР°С….
                    $inventoryItem->update(['status' => InventoryItemStateMachine::AVAILABLE]);
                } elseif ($inventoryItem->status === InventoryItemStateMachine::HELD && (int) $inventoryItem->available_quantity > 0) {
                    $inventoryItem->update(['status' => InventoryItemStateMachine::AVAILABLE]);
                }
            }

            // РЎРЅСЏС‚СЊ СЃРѕРѕС‚РІРµС‚СЃС‚РІСѓСЋС‰РёР№ seat_hold (РёРЅР°С‡Рµ sweeper РІРµСЂРЅС‘С‚ quantity РїРѕРІС‚РѕСЂРЅРѕ).
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
     * РџСЂРѕРґР»РёС‚СЊ СЃРµСЂРІРµСЂРЅС‹Р№ С…РѕР»Рґ РєРѕСЂР·РёРЅС‹ (B5).
     *
     * РЎР±СЂР°СЃС‹РІР°РµС‚ `carts.expires_at` РЅР° `now + holdDurationMinutes()` Рё РїСЂРѕР»РѕРЅРіРёСЂСѓРµС‚
     * РІСЃРµ РµС‰С‘ Р°РєС‚РёРІРЅС‹Рµ `seat_holds` СЌС‚РѕР№ РєРѕСЂР·РёРЅС‹ РЅР° С‚РѕС‚ Р¶Рµ РґРµРґР»Р°Р№РЅ. РРґРµРјРїРѕС‚РµРЅС‚РЅРѕ:
     * СѓР¶Рµ СЃРЅСЏС‚С‹Рµ (`released_at`) РёР»Рё РєРѕРЅРІРµСЂС‚РёСЂРѕРІР°РЅРЅС‹Рµ (`converted_at`) С…РѕР»РґС‹ РЅРµ
     * С‚СЂРѕРіР°РµРј, РїРѕСЌС‚РѕРјСѓ РїРѕРІС‚РѕСЂРЅС‹Р№ РІС‹Р·РѕРІ РЅРµ В«РІРµСЂРЅС‘С‚В» РјРµСЃС‚Рѕ РґРІР°Р¶РґС‹.
     *
     * Р Р°РЅСЊС€Рµ РєРЅРѕРїРєР° В«РџСЂРѕРґР»РёС‚СЊВ» РІ UI Р»РёС€СЊ РїРµСЂРµСЂРёСЃРѕРІС‹РІР°Р»Р° Р»РѕРєР°Р»СЊРЅС‹Р№ С‚Р°Р№РјРµСЂ вЂ” СЃРµСЂРІРµСЂРЅС‹Р№
     * С…РѕР»Рґ РЅРµ РїСЂРѕРґР»РµРІР°Р»СЃСЏ (endpoint СЂР°СЃС€РёСЂРµРЅРёСЏ РѕС‚СЃСѓС‚СЃС‚РІРѕРІР°Р»). РўРµРїРµСЂСЊ РїСЂРѕРґР»РµРЅРёРµ
     * СЂРµР°Р»СЊРЅРѕ РѕС‚РѕРґРІРёРіР°РµС‚ СЃРµСЂРІРµСЂРЅС‹Р№ `expires_at`, Рё UI Р¶РёРІС‘С‚ СЂРѕРІРЅРѕ РґРѕ РЅРµРіРѕ.
     *
     * @throws ConflictError РєРѕСЂР·РёРЅР° РЅРµ РЅР°Р№РґРµРЅР°, РёСЃС‚РµРєР»Р°, РїСѓСЃС‚Р° РёР»Рё СѓР¶Рµ РЅРµ active
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
                ->where('status', CartStatus::ACTIVE)
                ->where('cart_token', $token)
                ->lockForUpdate()
                ->first();

            $now = CarbonImmutable::now();
            // РќРµ РІРѕСЃРєСЂРµСЃРёС‚СЊ РёСЃС‚С‘РєС€СѓСЋ РєРѕСЂР·РёРЅСѓ: sweeper РјРѕРі СѓР¶Рµ РІРµСЂРЅСѓС‚СЊ РјРµСЃС‚Р°
            // РІ РїСЂРѕРґР°Р¶Сѓ. РџСЂРѕРґР»РµРІР°С‚СЊ РјРѕР¶РЅРѕ С‚РѕР»СЊРєРѕ РґРµР№СЃС‚РІСѓСЋС‰РёР№ hold.
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

            // РџСЂРѕРґР»РµРІР°РµРј РЅРµР·Р°РІРёСЃРёРјРѕ РѕС‚ С‚РµРєСѓС‰РµРіРѕ РѕСЃС‚Р°С‚РєР° вЂ” РґР°Р¶Рµ РµСЃР»Рё РґРѕ РёСЃС‚РµС‡РµРЅРёСЏ
            // РѕСЃС‚Р°Р»Р°СЃСЊ РјРёРЅСѓС‚Р°, С…РѕР»Рґ СЃР±СЂР°СЃС‹РІР°РµС‚СЃСЏ РЅР° РїРѕР»РЅС‹Р№ СЃСЂРѕРє.
            $newExpires = $now->addMinutes(CartService::holdDurationMinutes());
            $cart->update(['expires_at' => $newExpires]);

            // РџСЂРѕР»РѕРЅРіРёСЂСѓРµРј С‚РѕР»СЊРєРѕ Р·Р°Р±Р»РѕРєРёСЂРѕРІР°РЅРЅС‹Рµ Р°РєС‚РёРІРЅС‹Рµ С…РѕР»РґС‹: СЃРЅСЏС‚С‹Рµ РїСЂРё
            // РѕС‚РјРµРЅРµ РјРµСЃС‚Р° РёР»Рё СѓР¶Рµ РєРѕРЅРІРµСЂС‚РёСЂРѕРІР°РЅРЅС‹Рµ РІ Р·Р°РєР°Р· С‚СЂРѕРіР°С‚СЊ РЅРµР»СЊР·СЏ. Р’ С‚РѕР№
            // Р¶Рµ С‚СЂР°РЅР·Р°РєС†РёРё СЌС‚Рѕ РёСЃРєР»СЋС‡Р°РµС‚ РіРѕРЅРєСѓ СЃРѕ sweeper'РѕРј Рё checkout.
            SeatHold::query()
                ->whereIn('id', $activeHolds->modelKeys())
                ->update(['expires_at' => $newExpires]);

            return ['expires_at' => $newExpires->toIso8601String()];
        });
    }

    /**
     * РћСЃРІРѕР±РѕРґРёС‚СЊ РёРЅРІРµРЅС‚Р°СЂСЊ РєРѕСЂР·РёРЅС‹: РІРµСЂРЅСѓС‚СЊ available_quantity РїРѕ РєР°Р¶РґРѕРјСѓ
     * unsold-РїСЂРµРґРјРµС‚Сѓ Рё СЃРЅСЏС‚СЊ Р°РєС‚РёРІРЅС‹Рµ seat_hold'С‹. РРґРµРјРїРѕС‚РµРЅС‚РЅРѕ: С…РѕР»РґС‹, СѓР¶Рµ
     * РєРѕРЅРІРµСЂС‚РёСЂРѕРІР°РЅРЅС‹Рµ (converted_at) РёР»Рё СЃРЅСЏС‚С‹Рµ (released_at), РЅРµ С‚СЂРѕРіР°СЋС‚СЃСЏ,
     * РїРѕСЌС‚РѕРјСѓ РїРѕРІС‚РѕСЂРЅС‹Р№ РІС‹Р·РѕРІ РЅРµ РјРѕР¶РµС‚ В«РІРµСЂРЅСѓС‚СЊВ» РјРµСЃС‚Рѕ РґРІР°Р¶РґС‹.
     *
     * РСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ checkout РїСЂРё РёСЃС‚РµС‡РµРЅРёРё РєРѕСЂР·РёРЅС‹ Рё `StaleOrderExpirer` РґР»СЏ
     * РёСЃС‚С‘РєС€РёС… Р·Р°РєР°Р·РѕРІ.
     *
     * Р’РђР–РќРћ: РІРѕР·РІСЂР°С‰Р°РµС‚ СЂРѕРІРЅРѕ `seat_holds.quantity`. РџРѕСЌС‚РѕРјСѓ РёРЅРІР°СЂРёР°РЅС‚ В«С…РѕР»Рґ
     * РїРѕРєСЂС‹РІР°РµС‚ РїРѕР·РёС†РёСЋВ» РёР· РґРѕРєР±Р»РѕРєР° РєР»Р°СЃСЃР° вЂ” РЅРµ РєРѕСЃРјРµС‚РёРєР°, Р° СѓСЃР»РѕРІРёРµ С‚РѕРіРѕ, С‡С‚Рѕ
     * СЌС‚РѕС‚ РјРµС‚РѕРґ РЅРµ С‚РµСЂСЏРµС‚ РјРµСЃС‚Р°.
     */
    public function releaseCartInventory(Cart $cart): void
    {
        // РћРґРёРЅ РёСЃС‚РѕС‡РЅРёРє РёСЃС‚РёРЅС‹ вЂ” seat_holds: РѕРЅРё СЃРѕР·РґР°СЋС‚СЃСЏ РЅР° addItem СЃ С‚РѕС‡РЅРѕР№
        // quantity РєР°Р¶РґРѕРіРѕ РјРµСЃС‚Р°. Р’РѕР·РІСЂР°С‚ РґРµР»Р°РµРј СЃС‚СЂРѕРіРѕ РїРѕ РµС‰С‘ РЅРµ СЃРЅСЏС‚С‹Рј С…РѕР»РґР°Рј,
        // РїРѕСЌС‚РѕРјСѓ РґРІРѕР№РЅРѕРіРѕ РІРѕР·РІСЂР°С‚Р° РЅРµС‚ РґР°Р¶Рµ РµСЃР»Рё РєРѕСЂР·РёРЅР° С‡РёСЃС‚РёР»Р°СЃСЊ С‡Р°СЃС‚РёС‡РЅРѕ.
        $holds = SeatHold::query()
            ->where('cart_id', $cart->id)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            $inventoryItem = InventoryItem::query()
                ->where('id', $hold->inventory_item_id)
                ->first();

            // РњРµСЃС‚Рѕ СѓР¶Рµ РѕРїР»Р°С‡РµРЅРѕ (sold) вЂ” РІРѕР·РІСЂР°С‰Р°С‚СЊ РЅРµР»СЊР·СЏ.
            if ($inventoryItem !== null && $inventoryItem->status !== InventoryItemStateMachine::SOLD) {
                $inventoryItem->increment('available_quantity', (int) $hold->quantity);

                if ((int) $inventoryItem->available_quantity >= (int) $inventoryItem->capacity) {
                    $inventoryItem->update(['status' => InventoryItemStateMachine::AVAILABLE]);
                } elseif ($inventoryItem->status === InventoryItemStateMachine::SOLD_OUT) {
                    $inventoryItem->update(['status' => InventoryItemStateMachine::HELD]);
                }
            }

            $hold->update(['released_at' => CarbonImmutable::now()]);
        }
    }

    /**
     * РњР°С‚РµСЂРёР°Р»РёР·РѕРІР°С‚СЊ С…РѕР»Рґ РІ `seat_holds`.
     *
     * A13: СЂР°РЅСЊС€Рµ Р·Р°РїРёСЃРё СЃРѕР·РґР°РІР°Р» С‚РѕР»СЊРєРѕ legacy-РїСѓС‚СЊ, Рё
     * `PaymentService::validateHoldsForOrder()` РЅРµ РЅР°С…РѕРґРёР» РЅРё РѕРґРЅРѕРіРѕ С…РѕР»РґР° вЂ”
     * В«Р·Р°С‰РёС‚Р°В» РѕС‚ РѕРїР»Р°С‚С‹ РїРѕСЃР»Рµ РёСЃС‚РµС‡РµРЅРёСЏ Р±С‹Р»Р° РјРµСЂС‚РІР°. РўРµРїРµСЂСЊ Сѓ РєР°Р¶РґРѕРіРѕ С…РѕР»РґР°
     * РµСЃС‚СЊ Р·Р°РїРёСЃСЊ СЃ TTL РєРѕСЂР·РёРЅС‹.
     */
    private function createHold(Cart $cart, InventoryItem $inventoryItem, int $quantity): void
    {
        // `public_id` здесь НЕ передаётся: его ставит `HasPublicId` на модели
        // `SeatHold`. Раньше значение генерировалось и тут — то есть у одного
        // правила было два владельца, и правка «сменим генератор» требовала
        // помнить об обоих. `seat_holds.public_id` — `CHAR(26) NOT NULL` без
        // значения по умолчанию, поэтому забыть трейт нельзя: `create()` упадёт
        // 1364. Это и стережёт `PublicIdGenerationCopyTest`.
        SeatHold::create([
            'inventory_item_id' => $inventoryItem->id,
            'session_id' => (int) $inventoryItem->session_id,
            'cart_id' => $cart->id,
            'quantity' => $quantity,
            'expires_at' => $cart->expires_at,
        ]);
    }

    /**
     * Р”РѕРІРµСЃС‚Рё С…РѕР»Рґ РґРѕ РЅРѕРІРѕРіРѕ РєРѕР»РёС‡РµСЃС‚РІР° РїРѕР·РёС†РёРё.
     *
     * Р—Р°С‡РµРј РѕС‚РґРµР»СЊРЅС‹Р№ С€Р°Рі. `seat_holds` СЃРѕР·РґР°РІР°Р»СЃСЏ РўРћР›Р¬РљРћ РїСЂРё РїРµСЂРІРѕР№ РІСЃС‚Р°РІРєРµ
     * РїРѕР·РёС†РёРё. РџРѕРІС‚РѕСЂРЅРѕРµ РґРѕР±Р°РІР»РµРЅРёРµ С‚РѕРіРѕ Р¶Рµ РјРµСЃС‚Р° (РґРІРѕР№РЅРѕР№ РєР»РёРє, retry, РІС‚РѕСЂР°СЏ
     * РІРєР»Р°РґРєР°) СЃРїРёСЃС‹РІР°Р»Рѕ РёРЅРІРµРЅС‚Р°СЂСЊ РµС‰С‘ СЂР°Р· Рё СѓРІРµР»РёС‡РёРІР°Р»Рѕ `cart_items.quantity`,
     * РЅРѕ С…РѕР»Рґ РЅРµ С‚СЂРѕРіР°Р»Рѕ. РРЅРІР°СЂРёР°РЅС‚ В«С…РѕР»Рґ = РїРѕР·РёС†РёСЏВ» РЅР°СЂСѓС€Р°Р»СЃСЏ, Рё РјРµСЃС‚Рѕ,
     * СЃРїРёСЃР°РЅРЅРѕРµ РІС‚РѕСЂС‹Рј РґРѕР±Р°РІР»РµРЅРёРµРј, РЅРµ РІРѕР·РІСЂР°С‰Р°Р» РЅРё РѕРґРёРЅ РїСѓС‚СЊ РѕСЃРІРѕР±РѕР¶РґРµРЅРёСЏ:
     * `releaseCartInventory()` РёРґС‘С‚ СЃС‚СЂРѕРіРѕ РїРѕ `seat_holds.quantity`.
     *
     * Р’РѕСЃРїСЂРѕРёР·РІРµРґРµРЅРѕ РЅР° Р¶РёРІРѕРј СЃС‚РµРЅРґРµ (С‚РµСЃС‚С‹
     * `CartWritePathTest::test_repeat_add_keeps_the_hold_in_step_with_the_cart_item`
     * Рё `test_expired_cart_returns_every_seat_it_reserved`): РїРѕР·РёС†РёСЏ 2 РјРµСЃС‚Р°,
     * С…РѕР»Рґ 1 РјРµСЃС‚Рѕ, РїРѕСЃР»Рµ РёСЃС‚РµС‡РµРЅРёСЏ РєРѕСЂР·РёРЅС‹ `available_quantity` РѕСЃС‚Р°РІР°Р»СЃСЏ
     * Р·Р°РЅРёР¶РµРЅРЅС‹Рј РЅР°РІСЃРµРіРґР°.
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
            // Р›РµРіР°СЃРё-РєРѕСЂР·РёРЅР° Р±РµР· РјР°С‚РµСЂРёР°Р»РёР·РѕРІР°РЅРЅРѕРіРѕ С…РѕР»РґР° (СЃРѕР·РґР°РЅР° РґРѕ A13) вЂ”
            // СЃРѕР·РґР°С‘Рј РµРіРѕ, С‡С‚РѕР±С‹ РёРЅРІР°СЂРёР°РЅС‚ РІРѕСЃСЃС‚Р°РЅРѕРІРёР»СЃСЏ, Р° РЅРµ РѕСЃС‚Р°Р»СЃСЏ РґС‹СЂРѕР№.
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
