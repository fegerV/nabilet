<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Cart\Domain\CartStatus;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Cart\Support\DbRetry;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Services\CustomerAccountService;
use Nabilet\Modules\Events\Models\Session;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;
use Nabilet\Modules\Loyalty\Services\ReferralService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\OrderItem;
use Nabilet\Modules\Orders\Models\PromoCode;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Pricing\Domain\OrderTotals;
use Nabilet\Modules\Pricing\Services\PromoCodeService;
use Nabilet\Modules\Sessions\Models\Session as SessionsSession;

/**
 * CartCheckoutService вЂ” РїСЂРµРІСЂР°С‰РµРЅРёРµ РєРѕСЂР·РёРЅС‹ РІ Р·Р°РєР°Р·.
 *
 * Р’С‹РґРµР»РµРЅРѕ РёР· `CartService` (P2). РџСЂРёС‡РёРЅР° РІС‹РґРµР»РµРЅРёСЏ РЅРµ С‚РѕР»СЊРєРѕ РІ СЂР°Р·РјРµСЂРµ: СЌС‚Рѕ
 * РµРґРёРЅСЃС‚РІРµРЅРЅР°СЏ РѕРїРµСЂР°С†РёСЏ, РєРѕС‚РѕСЂР°СЏ РїРµСЂРµСЃРµРєР°РµС‚ РіСЂР°РЅРёС†Сѓ РјРѕРґСѓР»РµР№ (Cart в†’ Orders в†’
 * Sessions) Рё СЃРѕР·РґР°С‘С‚ РґРµРЅРµР¶РЅС‹Р№ РґРѕРєСѓРјРµРЅС‚. Р’СЃС‘ РѕСЃС‚Р°Р»СЊРЅРѕРµ РІ РєРѕСЂР·РёРЅРµ вЂ”
 * РїРѕРґРіРѕС‚РѕРІРёС‚РµР»СЊРЅС‹Рµ РѕРїРµСЂР°С†РёРё, РєРѕС‚РѕСЂС‹Рµ РјРѕР¶РЅРѕ РѕС‚РєР°С‚РёС‚СЊ, РЅРёС‡РµРіРѕ РЅРµ РїРѕС‚РµСЂСЏРІ.
 *
 * Р—РђР’РРЎРРњРћРЎРўР¬ РћРў Р›РћРЇР›Р¬РќРћРЎРўР (С„Р°Р·Р° 2b)
 *   Р—РґРµСЃСЊ Р¶Рµ РїРѕСЏРІРёР»Р°СЃСЊ С‚СЂРµС‚СЊСЏ С‚РѕС‡РєР° РїРµСЂРµСЃРµС‡РµРЅРёСЏ РіСЂР°РЅРёС†С‹ вЂ” `Loyalty`: РѕС„РѕСЂРјР»РµРЅРёРµ
 *   СЃРїСЂР°С€РёРІР°РµС‚ Сѓ Р±РѕРЅСѓСЃРЅРѕРіРѕ СЃС‡С‘С‚Р°, СЃРєРѕР»СЊРєРѕ Р±РѕРЅСѓСЃРѕРІ РјРѕР¶РЅРѕ СЃРїРёСЃР°С‚СЊ, Рё Р·Р°РїРёСЃС‹РІР°РµС‚
 *   СЃРїРёСЃР°РЅРёРµ. Р Р°Р·РґРµР»РёС‚СЊ СЌС‚Рѕ РЅР° В«С‡РµРєР°СѓС‚ СЂРµС€Р°РµС‚ СЃР°РјВ» Рё В«Р»РѕСЏР»СЊРЅРѕСЃС‚СЊ РїРѕС‚РѕРј
 *   РїРѕРїСЂР°РІРёС‚В» РЅРµР»СЊР·СЏ: `total_amount` вЂ” РґРµРЅРµР¶РЅС‹Р№ РґРѕРєСѓРјРµРЅС‚, Рё РѕРЅ РѕР±СЏР·Р°РЅ Р±С‹С‚СЊ
 *   РїРѕСЃС‡РёС‚Р°РЅ РѕРґРёРЅ СЂР°Р·, РІРјРµСЃС‚Рµ СЃРѕ СЃРїРёСЃР°РЅРёРµРј. РџРѕСЌС‚РѕРјСѓ `BonusService` РІРЅРµРґСЂС‘РЅ СЃСЋРґР°
 *   РЅР°РїСЂСЏРјСѓСЋ (РєР°Рє Рё `PromoCodeService`), Р° РЅРµ РІС‹Р·С‹РІР°РµС‚СЃСЏ СЃРѕР±С‹С‚РёРµРј РїРѕСЃР»Рµ С„Р°РєС‚Р°.
 *
 * Р Р•Р¤Р•Р РђР›Р¬РќРђРЇ РђРўР РР‘РЈР¦РРЇ (С„Р°Р·Р° 4) вЂ” РўРћР–Р• Р—Р”Р•РЎР¬, РќРћ Р‘Р•Р— Р”Р•РќР•Р“
 *   `ReferralService::attachToOrder()` Р·Р°РІРѕРґРёС‚ РїСЂРёРіР»Р°С€РµРЅРёРµ РїРѕ Р°РґСЂРµСЃСѓ Р·Р°РєР°Р·Р°.
 *   Р’ РѕС‚Р»РёС‡РёРµ РѕС‚ Р±РѕРЅСѓСЃРѕРІ, СЌС‚Рѕ РЅРµ РґРµРЅРµР¶РЅС‹Р№ РґРѕРєСѓРјРµРЅС‚: РЅР°РіСЂР°РґР° Р¶РґС‘С‚ РѕРїР»Р°С‚С‹
 *   (`OrderReferralObserver`), Рё СЂР°СЃС…РѕРґРёС‚СЊСЃСЏ СЃ С‡РµРєР°СѓС‚РѕРј РµР№ РЅРµС‡РµРј. РџСЂРёС‡РёРЅР°
 *   СЂР°Р·РјРµС‰РµРЅРёСЏ С‚Р° Р¶Рµ вЂ” РµРґРёРЅСЃС‚РІРµРЅРЅРѕРµ РјРµСЃС‚Рѕ, РіРґРµ РёР·РІРµСЃС‚РµРЅ Рё Р°РґСЂРµСЃ РґСЂСѓРіР°, Рё
 *   Р°РєРєР°СѓРЅС‚, Р·Р°РІРµРґС‘РЅРЅС‹Р№ РЅР° С‡РµРєР°СѓС‚Рµ.
 */
final class CartCheckoutService
{
    public function __construct(
        private readonly CartItemService $items,
        private readonly PromoCodeService $promos = new PromoCodeService(),
        private readonly CustomerAccountService $customers = new CustomerAccountService(),
        private readonly BonusService $bonus = new BonusService(),
        // Р Р°РЅРЅРёР№ РґРѕСЃС‚СѓРї РєР»СѓР±Р° (С„Р°Р·Р° 3b): РіРµР№С‚ РїСЂРѕРґР°Р¶ РЅР° С‡РµРєР°СѓС‚Рµ СЃРґРІРёРіР°РµС‚ РѕРєРЅРѕ
        // РґР»СЏ РєР»СѓР±РЅРѕРіРѕ РїРѕРєСѓРїР°С‚РµР»СЏ. Р—РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ вЂ” РєР°Рє Сѓ СЃРѕСЃРµРґРµР№ РІС‹С€Рµ.
        private readonly LoyaltySettingsService $loyaltySettings = new LoyaltySettingsService(),
        // Р РµС„РµСЂР°Р»СЊРЅР°СЏ РїСЂРѕРіСЂР°РјРјР° (С„Р°Р·Р° 4): РєРѕРґ РёР· С‚РµР»Р° РѕС„РѕСЂРјР»РµРЅРёСЏ СЃРІСЏР·С‹РІР°РµС‚
        // Р·Р°РєР°Р· СЃ РїСЂРёРіР»Р°СЃРёРІС€РёРј. Р—РЅР°С‡РµРЅРёРµ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ вЂ” РєР°Рє Сѓ СЃРѕСЃРµРґРµР№ РІС‹С€Рµ.
        private readonly ReferralService $referrals = new ReferralService(),
    ) {
    }

    /**
     * РћС„РѕСЂРјРёС‚СЊ РєРѕСЂР·РёРЅСѓ вЂ” СЃРѕР·РґР°С‚СЊ Р·Р°РєР°Р· Рё РµРіРѕ РїРѕР·РёС†РёРё.
     *
     * @param \Nabilet\Modules\Core\Users\Models\User|null $buyer РІРѕС€РµРґС€РёР№ РїРѕРєСѓРїР°С‚РµР»СЊ (СЂР°РЅРЅРёР№ РґРѕСЃС‚СѓРї РєР»СѓР±Р°, С„Р°Р·Р° 3b)
     * @return array СЂРµР·СѓР»СЊС‚Р°С‚ РѕС„РѕСЂРјР»РµРЅРёСЏ СЃ РґР°РЅРЅС‹РјРё Р·Р°РєР°Р·Р°
     * @throws ConflictError          РєРѕСЂР·РёРЅР° РёСЃС‚РµРєР»Р° РёР»Рё РёРЅРІРµРЅС‚Р°СЂСЊ РёСЃС‡РµСЂРїР°РЅ
     * @throws DomainRuleViolation    РєРѕСЂР·РёРЅР° РїСѓСЃС‚Р° РёР»Рё РїСЂРѕРґР°Р¶Рё Р·Р°РєСЂС‹С‚С‹
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException РєРѕСЂР·РёРЅР° РЅРµ РЅР°Р№РґРµРЅР°
     */
    public function checkout(string $sessionId, array $customer = [], ?string $token = null, ?User $buyer = null): array
    {
        // D5: РѕС„РѕСЂРјРёС‚СЊ РјРѕР¶РЅРѕ С‚РѕР»СЊРєРѕ РєРѕСЂР·РёРЅСѓ С‚РѕРіРѕ Р¶Рµ РїРѕРєСѓРїР°С‚РµР»СЏ.
        $token = $this->requireToken($token);

        $expired = false;

        // Р¤Р»Р°Рі В«РєРѕСЂР·РёРЅР° РёСЃС‚РµРєР»Р°В» РїРѕРґРЅРёРјР°РµС‚СЃСЏ РР— С‚СЂР°РЅР·Р°РєС†РёРё РЅР°СЂСѓР¶Сѓ, Р° РЅРµ Р±СЂРѕСЃР°РµС‚СЃСЏ
        // РІРЅСѓС‚СЂРё РЅРµС‘. Р­С‚Рѕ РёСЃРїСЂР°РІР»РµРЅРёРµ РєРѕРЅРєСЂРµС‚РЅРѕРіРѕ РґРµС„РµРєС‚Р°, Р° РЅРµ СЃС‚РёР»РёСЃС‚РёРєР°:
        // `throw` РІРЅСѓС‚СЂРё `DB::transaction` РѕС‚РєР°С‚С‹РІР°РµС‚ С‚СЂР°РЅР·Р°РєС†РёСЋ С†РµР»РёРєРѕРј вЂ” РІРјРµСЃС‚Рµ
        // СЃ С‚РѕР»СЊРєРѕ С‡С‚Рѕ РІС‹РїРѕР»РЅРµРЅРЅС‹Рј РІРѕР·РІСЂР°С‚РѕРј РёРЅРІРµРЅС‚Р°СЂСЏ Рё РїРѕРјРµС‚РєРѕР№ `abandoned`.
        // Р Р°РЅСЊС€Рµ Р·РґРµСЃСЊ СЃС‚РѕСЏР» `throw`, Рё РѕС‡РёСЃС‚РєР° РЅРµ СЃРѕС…СЂР°РЅСЏР»Р°СЃСЊ РќРРљРћР“Р”Рђ: РєРѕСЂР·РёРЅР°
        // РѕСЃС‚Р°РІР°Р»Р°СЃСЊ `active`, РјРµСЃС‚Р° вЂ” СѓРґРµСЂР¶Р°РЅРЅС‹РјРё, Р° РєРѕРјРјРµРЅС‚Р°СЂРёР№ В«РѕСЃРІРѕР±РѕР¶РґР°РµС‚
        // РёРЅРІРµРЅС‚Р°СЂСЊ РЅРµРјРµРґР»РµРЅРЅРѕВ» РѕРїРёСЃС‹РІР°Р» РЅРµРґРѕСЃС‚РёР¶РёРјСѓСЋ РІРµС‚РєСѓ. Р’РѕСЃРїСЂРѕРёР·РІРµРґРµРЅРѕ
        // С‚РµСЃС‚РѕРј CartWritePathTest::test_expired_cart_returns_every_seat_it_reserved.
        // DbRetry (Р°СѓРґРёС‚ 1.3/D4-Р°СЂС…): deadlock 1213/40001 РЅР° С‡РµРєР°СѓС‚Рµ вЂ” СЃРїРѕСЂ Рѕ
        // РїРѕСЂСЏРґРєРµ Р±Р»РѕРєРёСЂРѕРІРѕРє, Р° РЅРµ РёСЃРїРѕСЂС‡РµРЅРЅС‹Рµ РґР°РЅРЅС‹Рµ; РїРѕРІС‚РѕСЂ С‚СЂР°РЅР·Р°РєС†РёРё
        // Р±РµР·РѕРїР°СЃРµРЅ Рё РІРјРµСЃС‚Рѕ 500 РґР°С‘С‚ РїРѕРєСѓРїР°С‚РµР»СЋ РѕР±С‹С‡РЅС‹Р№ СѓСЃРїРµС….
        //
        // РћР‘Р«Р§РќРћР• Р·Р°РјС‹РєР°РЅРёРµ, Р° РЅРµ `fn()`: СЃС‚СЂРµР»РѕС‡РЅС‹Рµ С„СѓРЅРєС†РёРё Р·Р°С…РІР°С‚С‹РІР°СЋС‚
        // РїРµСЂРµРјРµРЅРЅС‹Рµ РўРћР›Р¬РљРћ РїРѕ Р·РЅР°С‡РµРЅРёСЋ, С‡С‚Рѕ СЂР°Р·РѕСЂРІР°Р»Рѕ Р±С‹ `&$expired` вЂ” С„Р»Р°Рі
        // В«РєРѕСЂР·РёРЅР° РёСЃС‚РµРєР»Р°В», РїСЂРёСЃРІРѕРµРЅРЅС‹Р№ РІРЅСѓС‚СЂРё С‚СЂР°РЅР·Р°РєС†РёРё, С‚РµСЂСЏР»СЃСЏ Р±С‹, Рё
        // РїРѕРєСѓРїР°С‚РµР»СЊ РїРѕР»СѓС‡Р°Р» 200 РІРјРµСЃС‚Рѕ 409.
        $result = DbRetry::run(function () use ($sessionId, $token, $customer, $buyer, &$expired): ?array {
            return DB::transaction(function () use ($sessionId, $token, $customer, $buyer, &$expired): ?array {
                // РЎР±СЂРѕСЃ С„Р»Р°РіР° РЅР° РєР°Р¶РґРѕР№ РїРѕРїС‹С‚РєРµ: РѕС‚РєР°С‚ РїРѕ deadlock РјРѕР¶РµС‚ РѕСЃС‚Р°РІРёС‚СЊ
                // В«РёСЃС‚РµРєР»Р°В» РѕС‚ РЅРµСѓРґР°РІС€РµР№СЃСЏ РїРѕРїС‹С‚РєРё, Рё СЃРЅР°СЂСѓР¶Рё СЌС‚Рѕ РІС‹РіР»СЏРґРµР»Рѕ Р±С‹
                // CART_EXPIRED РІРјРµСЃС‚Рѕ С‡РµСЃС‚РЅРѕРіРѕ РїРѕРІС‚РѕСЂР°.
                $expired = false;

                $cart = Cart::query()
                    ->where('session_id', $sessionId)
                    ->where('status', CartStatus::ACTIVE)
                    ->where('cart_token', $token)
                    ->with(['items.inventoryItem'])
                    ->lockForUpdate()
                    ->first();

                if ($cart === null) {
                    // D-8/S8: 404 РЅР° РїРѕРІС‚РѕСЂРЅС‹Р№ checkout РїРѕСЃР»Рµ СѓСЃРїРµС€РЅРѕРіРѕ РѕС„РѕСЂРјР»РµРЅРёСЏ
                    // РІРѕР»РЅРѕРІР°Р» РїРѕРєСѓРїР°С‚РµР»СЏ Р±РѕР»СЊС€Рµ, С‡РµРј РёРЅС„РѕСЂРјРёСЂРѕРІР°Р». Р•СЃР»Рё РєРѕСЂР·РёРЅР°
                    // РєРѕРЅРІРµСЂС‚РёСЂРѕРІР°РЅР° СЌС‚РёРј Р¶Рµ С‚РѕРєРµРЅРѕРј Рё Сѓ РЅРµС‘ РµСЃС‚СЊ Р·Р°РєР°Р· вЂ” С‡РµСЃС‚РЅРѕ
                    // РІРѕР·РІСЂР°С‰Р°РµРј РўРѕС‚ Р¶Рµ Р·Р°РєР°Р· (РёРґРµРјРїРѕС‚РµРЅС‚РЅС‹Р№ replay СЃ 'replayed':
                    // true), РІРјРµСЃС‚Рѕ РёРЅС„СЂР°СЃС‚СЂСѓРєС‚СѓСЂРЅРѕРіРѕ NOT_FOUND. РЎР»СѓС‡Р°Р№ В«РєРѕСЂР·РёРЅР°
                    // РІРѕРѕР±С‰Рµ РЅРµ РЅР°С€Р°В» РѕСЃС‚Р°С‘С‚СЃСЏ 404 РїРѕ РґРѕРјРµРЅСѓ (NotFoundError).
                    $replayed = $this->replayConvertedOrder($sessionId, $token);

                    if ($replayed !== null) {
                        return $replayed;
                    }

                    throw new NotFoundError('Cart', $sessionId);
                }

                return $this->convert($cart, $customer, $expired, null, $buyer);
            });
        });

        // Р’РѕР·РІСЂР°С‚ РёРЅРІРµРЅС‚Р°СЂСЏ СѓР¶Рµ Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅ С‚СЂР°РЅР·Р°РєС†РёРµР№ вЂ” С‚РѕР»СЊРєРѕ С‚РµРїРµСЂСЊ РјРѕР¶РЅРѕ
        // РѕС‚РєР°Р·Р°С‚СЊ, РЅРµ РїРѕС‚РµСЂСЏРІ РѕС‡РёСЃС‚РєСѓ.
        if ($expired) {
            throw ConflictError::cartExpired();
        }

        return $result ?? [];
    }

    /**
     * РћС„РѕСЂРјРёС‚СЊ РєРѕСЂР·РёРЅСѓ РїРѕ РµС‘ `public_id` вЂ” С„РѕСЂРјР° СЃРїРµРєРё (`POST /api/v1/orders`,
     * `CreateOrderRequest.cart_id`).
     *
     * Р—РђР§Р•Рњ Р’РўРћР РћР™ Р’РҐРћР”. РЎРїРµРєР° Р°РґСЂРµСЃСѓРµС‚ РєРѕСЂР·РёРЅСѓ РёР· РїСѓС‚Рё (`/carts/{cart}`) Рё
     * СЃРѕР·РґР°С‘С‚ Р·Р°РєР°Р· РёР· `cart_id`; РїСЂРёР»РѕР¶РµРЅРёРµ Р°РґСЂРµСЃРѕРІР°Р»Рѕ РµС‘ РїР°СЂРѕР№
     * (`session_id` + `X-Cart-Token`). РћР±Р° РІС…РѕРґР° РІРµРґСѓС‚ РІ РћР”РќРЈ Рё С‚Сѓ Р¶Рµ
     * `convert()` вЂ” СЂР°СЃС…РѕРґРёС‚СЊСЃСЏ Р·РґРµСЃСЊ Р·РЅР°С‡РёР»Рѕ Р±С‹ РїРѕР»СѓС‡РёС‚СЊ РґРІРµ СЂРµР°Р»РёР·Р°С†РёРё
     * РѕС„РѕСЂРјР»РµРЅРёСЏ Р·Р°РєР°Р·Р°.
     *
     * РџР РђР’Рћ РќРђ РљРћР Р—РРќРЈ. Р’Р»Р°РґРµР»РµС† вЂ” С‚РѕС‚ Р¶Рµ, С‡С‚Рѕ Рё Сѓ CRUD-РїСѓС‚РµР№: РіРѕСЃС‚РµРІРѕР№ С‚РѕРєРµРЅ
     * РёР· `X-Cart-Token` СЃРѕРІРїР°РґР°РµС‚ СЃ `carts.cart_token`, Р»РёР±Рѕ Bearer-РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ
     * РІР»Р°РґРµРµС‚ РєРѕСЂР·РёРЅРѕР№ (`carts.user_id`). Р§СѓР¶Р°СЏ РєРѕСЂР·РёРЅР° вЂ” 404, Р° РЅРµ 403: СЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёРµ
     * РёР»Рё РЅРµСЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёРµ С‡СѓР¶РѕРіРѕ `public_id` вЂ” РЅРµ РёРЅС„РѕСЂРјР°С†РёСЏ РґР»СЏ СЃРїСЂР°С€РёРІР°СЋС‰РµРіРѕ.
     *
     * @param \Nabilet\Modules\Core\Users\Models\User|null $buyer РІРѕС€РµРґС€РёР№ РїРѕРєСѓРїР°С‚РµР»СЊ (СЂР°РЅРЅРёР№ РґРѕСЃС‚СѓРї РєР»СѓР±Р°, С„Р°Р·Р° 3b)
     * @throws ConflictError          РєРѕСЂР·РёРЅР° РёСЃС‚РµРєР»Р° РёР»Рё РёРЅРІРµРЅС‚Р°СЂСЊ РёСЃС‡РµСЂРїР°РЅ
     * @throws DomainRuleViolation    РєРѕСЂР·РёРЅР° РїСѓСЃС‚Р° РёР»Рё РїСЂРѕРґР°Р¶Рё Р·Р°РєСЂС‹С‚С‹
     */
    public function checkoutByPublicId(string $publicId, array $customer = [], ?string $token = null, ?int $userId = null, ?User $buyer = null): array
    {
        $expired = false;

        // РћР±С‹С‡РЅРѕРµ Р·Р°РјС‹РєР°РЅРёРµ (РЅРµ fn()) вЂ” РїРѕ С‚РѕР№ Р¶Рµ РїСЂРёС‡РёРЅРµ: &$expired РґРѕР»Р¶РµРЅ
        // РїСЂРѕР±РёС‚СЊСЃСЏ РЅР°СЂСѓР¶Сѓ С‡РµСЂРµР· СЃР»РѕРё РїРѕРІС‚РѕСЂРѕРІ.
        $result = DbRetry::run(function () use ($publicId, $customer, $token, $userId, $buyer, &$expired): ?array {
            return DB::transaction(function () use ($publicId, $customer, $token, $userId, $buyer, &$expired): ?array {
                $expired = false;

                $cart = $this->lockOwnedByPublicId($publicId, $token, $userId);

                return $this->convert($cart, $customer, $expired, $userId, $buyer);
            });
        });

        if ($expired) {
            throw ConflictError::cartExpired();
        }

        return $result ?? [];
    }

    /**
     * РўРѕРєРµРЅ РїРѕРєСѓРїР°С‚РµР»СЏ РѕР±СЏР·Р°С‚РµР»РµРЅ: Р±РµР· РЅРµРіРѕ РєРѕСЂР·РёРЅР° РЅРµ РЅР°С…РѕРґРёС‚СЃСЏ, Р° РЅРµ
     * В«РЅР°С…РѕРґРёС‚СЃСЏ Р»СЋР±Р°СЏВ». РЎРѕРѕР±С‰РµРЅРёРµ С‚Рѕ Р¶Рµ, С‡С‚Рѕ Рё СЂР°РЅСЊС€Рµ, вЂ” РєРѕРЅС‚СЂР°РєС‚ 422.
     */
    private function requireToken(?string $token): string
    {
        $token = trim((string) $token);

        if ($token === '') {
            throw new ValidationError(['cart_token' => ['The cart token is required.']], 'Cart token is required.');
        }

        return $token;
    }

    /**
     * РљРѕСЂР·РёРЅР° РїРѕ `public_id`, РїСЂРёРЅР°РґР»РµР¶Р°С‰Р°СЏ СЃРїСЂР°С€РёРІР°СЋС‰РµРјСѓ, вЂ” РїРѕРґ Р±Р»РѕРєРёСЂРѕРІРєРѕР№.
     *
     * 404 Рё РЅР° В«РЅРµС‚ С‚Р°РєРѕР№В», Рё РЅР° В«РЅРµ С‚РІРѕСЏВ»: СЂР°Р·Р»РёС‡Р°С‚СЊ РёС… вЂ” Р·РЅР°С‡РёС‚ РїРѕРґС‚РІРµСЂР¶РґР°С‚СЊ
     * СЃСѓС‰РµСЃС‚РІРѕРІР°РЅРёРµ С‡СѓР¶РёС… РєРѕСЂР·РёРЅ РїРѕ РёС… РёРґРµРЅС‚РёС„РёРєР°С‚РѕСЂСѓ.
     */
    private function lockOwnedByPublicId(string $publicId, ?string $token, ?int $userId): Cart
    {
        $token = trim((string) $token);

        return Cart::query()
            ->where('public_id', $publicId)
            ->where('status', CartStatus::ACTIVE)
            ->where(function ($query) use ($token, $userId): void {
                if ($userId !== null) {
                    $query->where('user_id', $userId);
                }

                if ($token !== '') {
                    $query->orWhere('cart_token', $token);
                }
            })
            ->with(['items.inventoryItem'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * РљС‚Рѕ РїРѕРєСѓРїР°С‚РµР»СЊ РґР»СЏ СЂР°РЅРЅРµРіРѕ РґРѕСЃС‚СѓРїР° (С„Р°Р·Р° 3b).
     *
     * РўСЂРё РёСЃС‚РѕС‡РЅРёРєР°, РѕС‚ СЃР°РјРѕРіРѕ Р°РІС‚РѕСЂРёС‚РµС‚РЅРѕРіРѕ:
     *   1. РЇРІРЅРѕ РїРµСЂРµРґР°РЅРЅС‹Р№ РїРѕРєСѓРїР°С‚РµР»СЊ вЂ” РєРѕРЅС‚СЂРѕР»Р»РµСЂ РѕС‚РґР°Р» `$request->user()`.
     *   2. `$userId` (РІС‚РѕСЂРѕР№ РІС…РѕРґ, СЃРїРµРєР° `POST /orders`) вЂ” РіСЂСѓР·РёРј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.
     *   3. Р’Р»Р°РґРµР»РµС† РєРѕСЂР·РёРЅС‹ (`carts.user_id`) вЂ” РєРѕСЂР·РёРЅР° СѓР¶Рµ РїСЂРёРЅР°РґР»РµР¶Р°Р»Р°
     *      РїРѕРєСѓРїР°С‚РµР»СЋ РґРѕ РѕС„РѕСЂРјР»РµРЅРёСЏ.
     * Р’СЃРµ NULL вЂ” РіРѕСЃС‚СЊ: СЂР°РЅРЅРёР№ РґРѕСЃС‚СѓРї РЅРµ РїРѕР»РѕР¶РµРЅ (0 С‡Р°СЃРѕРІ).
     */
    private function earlyAccessBuyer(Cart $cart, ?int $userId, ?User $buyer): ?User
    {
        if ($buyer !== null) {
            return $buyer;
        }

        if ($userId !== null) {
            return User::query()->find($userId);
        }

        return $cart->user;
    }

    /**
     * РљС‚Рѕ РІР»Р°РґРµР»РµС† СЃРѕР·РґР°РІР°РµРјРѕРіРѕ Р·Р°РєР°Р·Р°.
     *
     * РўСЂРё РёСЃС‚РѕС‡РЅРёРєР°, РІ РїРѕСЂСЏРґРєРµ СѓР±С‹РІР°РЅРёСЏ Р°РІС‚РѕСЂРёС‚РµС‚РЅРѕСЃС‚Рё:
     *
     *   1. РЇРІРЅРѕ РїРµСЂРµРґР°РЅРЅС‹Р№ `$userId` вЂ” РІРѕС€РµРґС€РёР№ РїРѕРєСѓРїР°С‚РµР»СЊ РёР»Рё СЃРѕС‚СЂСѓРґРЅРёРє,
     *      РѕС„РѕСЂРјР»СЏСЋС‰РёР№ Р·Р°РєР°Р· (РІС‚РѕСЂРѕР№ РІС…РѕРґ, `checkoutByPublicId`).
     *   2. РЈР¶Рµ РїСЂРѕСЃС‚Р°РІР»РµРЅРЅС‹Р№ РІР»Р°РґРµР»РµС† РєРѕСЂР·РёРЅС‹ (`carts.user_id`) вЂ” РїРѕРєСѓРїР°С‚РµР»СЊ,
     *      РІРѕС€РµРґС€РёР№ Р”Рћ РЅР°РїРѕР»РЅРµРЅРёСЏ РєРѕСЂР·РёРЅС‹.
     *   3. РђРєРєР°СѓРЅС‚, РІС‹РІРµРґРµРЅРЅС‹Р№ РёР· Р°РґСЂРµСЃР° Р·Р°РєР°Р·Р° (`CustomerAccountService`) вЂ”
     *      РіРѕСЃС‚РµРІР°СЏ РІРёС‚СЂРёРЅР°. РђРєРєР°СѓРЅС‚ СЃРѕР·РґР°С‘С‚СЃСЏ Р·РґРµСЃСЊ, РµСЃР»Рё Р°РґСЂРµСЃР° РµС‰С‘ РЅРµС‚.
     *
     * РџРћР‘РћР§РќР«Р™ Р­Р¤Р¤Р•РљРў, РљРћРўРћР Р«Р™ Р Р•РЎРўР¬ РЎРњР«РЎР›. РќР°Р№РґРµРЅРЅР°СЏ Р»РёС‡РЅРѕСЃС‚СЊ Р·Р°РїРёСЃС‹РІР°РµС‚СЃСЏ
     * РІ `carts.user_id`. `PromoCodeService::buildContext()` С‡РёС‚Р°РµС‚ РїРѕРєСѓРїР°С‚РµР»СЏ
     * РРњР•РќРќРћ РѕС‚С‚СѓРґР°, Р° РЅРµ РёР· Р°СЂРіСѓРјРµРЅС‚Р°, вЂ” Р±РµР· СЌС‚РѕР№ Р·Р°РїРёСЃРё РїСЂРѕРјРѕРєРѕРґ РІСЃС‘ СЂР°РІРЅРѕ
     * РѕС†РµРЅРёР»СЃСЏ Р±С‹ РєР°Рє РіРѕСЃС‚РµРІРѕР№, Рё `per_user_limit` (РўР— В§86) РЅРµ СЃСЂР°Р±РѕС‚Р°Р» Р±С‹.
     * Р—Р°РїРёСЃСЊ РёРґС‘С‚ РІ С‚РѕР№ Р¶Рµ С‚СЂР°РЅР·Р°РєС†РёРё, С‡С‚Рѕ Рё Р·Р°РєР°Р·: РѕС‚РєР°С‚ РѕС„РѕСЂРјР»РµРЅРёСЏ
     * РѕС‚РєР°С‚С‹РІР°РµС‚ Рё РІР»Р°РґРµР»СЊС†Р°.
     *
     * `null` РІРѕР·РІСЂР°С‰Р°РµС‚СЃСЏ С‚РѕР»СЊРєРѕ РєРѕРіРґР° Р°РґСЂРµСЃ Р·Р°РєР°Р·Р° РїСѓСЃС‚ РёР»Рё СЃРёРЅС‚Р°РєСЃРёС‡РµСЃРєРё
     * РЅРµРІРµСЂРµРЅ вЂ” С‚РѕРіРґР° Р·Р°РєР°Р· РѕСЃС‚Р°С‘С‚СЃСЏ РіРѕСЃС‚РµРІС‹Рј, СЂРѕРІРЅРѕ РєР°Рє Рё СЂР°РЅСЊС€Рµ.
     */
    private function resolveBuyerId(Cart $cart, array $customer, ?int $userId): ?int
    {
        $buyerId = $userId ?? ($cart->user_id === null ? null : (int) $cart->user_id);

        if ($buyerId === null) {
            $buyerId = $this->customers->resolveForCheckout(
                (string) ($customer['customer_email'] ?? ''),
                $customer['customer_name'] ?? null,
                $customer['customer_phone'] ?? null,
            );
        }

        if ($buyerId !== null && (int) $cart->user_id !== $buyerId) {
            $cart->update(['user_id' => $buyerId]);
        }

        return $buyerId;
    }

    /**
     * РћР±С‰РµРµ С‚РµР»Рѕ РѕС„РѕСЂРјР»РµРЅРёСЏ: РІСЃС‘, С‡С‚Рѕ РїСЂРѕРёСЃС…РѕРґРёС‚ РџРћРЎР›Р• С‚РѕРіРѕ, РєР°Рє РєРѕСЂР·РёРЅР°
     * РЅР°Р№РґРµРЅР° Рё Р·Р°Р±Р»РѕРєРёСЂРѕРІР°РЅР°.
     *
     * Р’С‹РЅРµСЃРµРЅРѕ РёР· `checkout()`, РєРѕРіРґР° Сѓ РѕС„РѕСЂРјР»РµРЅРёСЏ РїРѕСЏРІРёР»СЃСЏ РІС‚РѕСЂРѕР№ РІС…РѕРґ (РїРѕ
     * `public_id`): РґРІР° РїСѓР±Р»РёС‡РЅС‹С… РјРµС‚РѕРґР°, РѕРґРЅРѕ С‚РµР»Рѕ. Р Р°СЃС…РѕР¶РґРµРЅРёРµ РґРІСѓС… С‚РµР» вЂ” СЌС‚Рѕ
     * РґРІР° СЂР°Р·РЅС‹С… РїРѕРІРµРґРµРЅРёСЏ В«СЃРѕР·РґР°С‚СЊ Р·Р°РєР°Р·В», С‚Рѕ РµСЃС‚СЊ Р±Р°Рі, РєРѕС‚РѕСЂС‹Р№ РЅР°С…РѕРґСЏС‚ РІ
     * РїСЂРѕРґРµ.
     */
    private function convert(Cart $cart, array $customer, bool &$expired, ?int $userId = null, ?User $buyer = null): ?array
    {
        // Check cart expiration
            if ($cart->expires_at < CarbonImmutable::now()) {
                $cart->update(['status' => CartStatus::ABANDONED]);

                // A13: РёСЃС‚С‘РєС€Р°СЏ РєРѕСЂР·РёРЅР° РѕСЃРІРѕР±РѕР¶РґР°РµС‚ РёРЅРІРµРЅС‚Р°СЂСЊ РЅРµРјРµРґР»РµРЅРЅРѕ вЂ”
                // РёРЅР°С‡Рµ РјРµСЃС‚Р° Р·Р°РІРёСЃР°СЋС‚ РІ held/sold РґРѕ СЂР°Р±РѕС‚С‹ sweeper'Р°.
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
            //
            // Р¤Р°Р·Р° 3b: РєР»СѓР±РЅС‹Р№ РїРѕРєСѓРїР°С‚РµР»СЊ РїСЂРѕРІРµСЂСЏРµС‚СЃСЏ С‚РµРј Р¶Рµ СЃРґРІРёРіРѕРј РѕРєРЅР°, С‡С‚Рѕ Рё
            // РЅР° СЃРѕР·РґР°РЅРёРё РєРѕСЂР·РёРЅС‹ вЂ” РёРЅР°С‡Рµ В«СЂР°РЅРЅРёР№ РґРѕСЃС‚СѓРїВ» РѕС‚РєСЂС‹РІР°Р»СЃСЏ Р±С‹ С‚РѕР»СЊРєРѕ РЅР°
            // РєРѕСЂР·РёРЅСѓ, Р° РѕРїР»Р°С‚РёС‚СЊ РјРµСЃС‚Рѕ СЂР°РЅСЊС€Рµ РІСЂРµРјРµРЅРё Р±С‹Р»Рѕ РЅРµР»СЊР·СЏ.
            $session = Session::findOrFailBySessionId((string) $cart->session_id);

            $earlyAccessHours = $this->loyaltySettings->earlyAccessHoursForUser(
                $this->earlyAccessBuyer($cart, $userId, $buyer)
            );

            if (! $session->isSellableAt(null, $earlyAccessHours)) {
                throw DomainRuleViolation::salesClosed(
                    (string) ($session->public_id ?? $session->id),
                    ['reason' => $session->salesBlockReason(null, $earlyAccessHours)]
                );
            }

            // Validate all items still have available inventory.
            // addItem() already reserved (decremented) available_quantity for
            // these cart items вЂ” the seat belongs to THIS cart now. A concurrent
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
            $cart->update(['status' => CartStatus::CONVERTED]);

            // A6 (РїР»Р°РЅ b): РґРѕ РѕРїР»Р°С‚С‹ РјРµСЃС‚Р° РќР• РїРѕРјРµС‡Р°СЋС‚СЃСЏ 'sold'. sold вЂ” СЌС‚Рѕ
            // РїРѕРґС‚РІРµСЂР¶РґС‘РЅРЅР°СЏ РїСЂРѕРґР°Р¶Р° (InventoryItemStateMachine: held в†’ sold РЅР°
            // РїРѕРґС‚РІРµСЂР¶РґРµРЅРёРё РїР»Р°С‚РµР¶Р°). РџСЂРѕРјРµР¶СѓС‚РѕС‡РЅРѕРµ СЃРѕСЃС‚РѕСЏРЅРёРµ вЂ” available_quantity
            // СѓР¶Рµ СѓРґРµСЂР¶Р°РЅ С…РѕР»РґР°РјРё; СЃС‚Р°С‚СѓСЃ РѕСЃС‚Р°С‘С‚СЃСЏ held/sold_out. Р­С‚Рѕ СЃРЅРёРјР°РµС‚
            // РґРµС„РµРєС‚ В«РјРµСЃС‚Р° СЃРіРѕСЂР°СЋС‚ РїСЂРё РЅРµРѕРїР»Р°С‚РµВ»: РЅРµРѕРїР»Р°С‡РµРЅРЅС‹Рµ РјРµСЃС‚Р° РЅРёРєРѕРіРґР° РЅРµ
            // Р·Р°РІРёСЃР°СЋС‚ РІ sold, Р° РѕСЃРІРѕР±РѕР¶РґР°СЋС‚СЃСЏ sweeper'РѕРј / checkout-expiry.
            foreach ($cart->items as $item) {
                if ($item->inventoryItem !== null && $item->inventoryItem->status === InventoryItemStateMachine::AVAILABLE) {
                    $item->inventoryItem->update(['status' => InventoryItemStateMachine::HELD]);
                }
            }

            // РҐРѕР»РґС‹ РєРѕСЂР·РёРЅС‹ РїРµСЂРµР¶РёРІР°СЋС‚ checkout: converted_at СЃС‚Р°РІРёС‚СЃСЏ С‚РѕР»СЊРєРѕ РЅР°
            // payment.succeeded (markHoldsAsConverted). Р•СЃР»Рё РїР»Р°С‚С‘Р¶ С‚Р°Рє Рё РЅРµ
            // РїСЂРѕС€С‘Р», Р·Р°РєР°Р· Р·Р°Р±РµСЂС‘С‚ orders:expire-sweeper Рё РІРµСЂРЅС‘С‚ РјРµСЃС‚Р°.

            // Create the order: checkout succeeded, seats are sold, cart is
            // converted вЂ” persist the sale so it appears in the admin orders list.
            $session = $cart->session()->first();
            $event = $session?->event()->first();
            $organizationId = $event?->organization_id ?? $session?->venue?->organization_id;

            // Fail-closed: организация заказа НЕ угадывается. Колонки
            // `events.organization_id` и `venues.organization_id` — NOT NULL
            // (аудиты R-7/C-4), а сам сеанс уже найден выше
            // (`Session::findOrFailBySessionId`), поэтому недоопределённость —
            // ошибка целостности данных, а не повод молча приписать заказ
            // организации №1. Прежний фолбэк `?? 1` тихо перекладывал чужой
            // заказ в арендатора №1 — точку пересечения с его данными.
            if ($organizationId === null) {
                throw new DomainRuleViolation(
                    'Cannot resolve organization for the cart session.',
                    'CART_ORGANIZATION_UNRESOLVED',
                );
            }

            // РђРєРєР°СѓРЅС‚ РїРѕРєСѓРїР°С‚РµР»СЏ: Р·Р°РєР°Р· РїРѕР»СѓС‡Р°РµС‚ РІР»Р°РґРµР»СЊС†Р°, Р° РїСЂРѕРјРѕРєРѕРґ вЂ”
            // Р»РёС‡РЅРѕСЃС‚СЊ, РїРѕ РєРѕС‚РѕСЂРѕР№ СЃС‡РёС‚Р°РµС‚СЃСЏ Р»РёРјРёС‚.
            //
            // Р Р°РЅСЊС€Рµ РІР»Р°РґРµР»СЊС†РµРј Р±С‹Р» С‚РѕР»СЊРєРѕ Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅРЅС‹Р№ РїРѕР»СЊР·РѕРІР°С‚РµР»СЊ, Р°
            // Сѓ РіРѕСЃС‚РµРІРѕРіРѕ Р·Р°РєР°Р·Р° `user_id` РѕСЃС‚Р°РІР°Р»СЃСЏ NULL. РЎР»РµРґСЃС‚РІРёРµ РЅРµ
            // РєРѕСЃРјРµС‚РёС‡РµСЃРєРѕРµ: `PromoCodeService::buildContext()` Р±РµСЂС‘С‚ Р»РёС‡РЅРѕСЃС‚СЊ РёР·
            // `carts.user_id`, Р° `userRedemptions()` РїСЂРё `null` РІРѕР·РІСЂР°С‰Р°РµС‚ 0 вЂ”
            // Р·РЅР°С‡РёС‚ `per_user_limit` Сѓ РіРѕСЃС‚СЏ РЅРµ РґРµР№СЃС‚РІРѕРІР°Р» Р’РћРћР‘Р©Р•, Р° СѓСЃР»РѕРІРёРµ
            // `first_purchase` РїСЂРѕС…РѕРґРёР»Рѕ Р±РµСЃРєРѕРЅРµС‡РЅРѕ.
            //
            // РџРћР РЇР”РћРљ Р’РђР–Р•Рќ: РґРѕ РѕС†РµРЅРєРё РїСЂРѕРјРѕРєРѕРґР°, Р° РЅРµ РїРѕСЃР»Рµ. РџСЂРѕСЃС‚Р°РІР»РµРЅРЅР°СЏ
            // РїРѕР·Р¶Рµ Р»РёС‡РЅРѕСЃС‚СЊ РЅРµ РїРѕРїР°РґС‘С‚ РІ РєРѕРЅС‚РµРєСЃС‚ РѕС†РµРЅРєРё, Рё РєРѕРґ СЃРЅРѕРІР° Р±СѓРґРµС‚
            // РѕС†РµРЅС‘РЅ РєР°Рє РіРѕСЃС‚РµРІРѕР№.
            $buyerId = $this->resolveBuyerId($cart, $customer, $userId);

            // РџСЂРѕРјРѕРєРѕРґ: РєРѕРґ РїСЂРѕРІРµСЂСЏРµС‚СЃСЏ Р”Рћ СЃРѕР·РґР°РЅРёСЏ Р·Р°РєР°Р·Р° вЂ” С‡РёСЃС‚Р°СЏ РѕС†РµРЅРєР° С‡РµСЂРµР·
            // С‚РѕС‚ Р¶Рµ PromoEvaluator, С‡С‚Рѕ Рё POST /promo-codes/validate (РІС‚РѕСЂРѕРіРѕ
            // РёСЃС‚РѕС‡РЅРёРєР° РїСЂР°РІРґС‹ Рѕ СЃРєРёРґРєРµ РЅРµС‚). РћС‚РєР°Р· Р±СЂРѕСЃР°РµС‚ РёСЃРєР»СЋС‡РµРЅРёРµ РІРЅСѓС‚СЂРё
            // DB::transaction: Р·Р°РєР°Р· РЅРµ СЃРѕР·РґР°С‘С‚СЃСЏ, РїРѕРјРµС‚РєР° РєРѕСЂР·РёРЅС‹ `converted`
            // РѕС‚РєР°С‚С‹РІР°РµС‚СЃСЏ, РёРЅРІРµРЅС‚Р°СЂСЊ РѕСЃС‚Р°С‘С‚СЃСЏ СѓРґРµСЂР¶Р°РЅРЅС‹Рј С…РѕР»РґРѕРј вЂ” РїРѕРєСѓРїР°С‚РµР»СЊ
            // РјРѕР¶РµС‚ РёСЃРїСЂР°РІРёС‚СЊ РєРѕРґ Рё РїРѕРІС‚РѕСЂРёС‚СЊ РѕС„РѕСЂРјР»РµРЅРёРµ.
            $promoCode = null;
            $promoEvaluation = null;

            if (!empty($customer['promo_code'])) {
                $promoCode = $this->promos->findByCode(
                    $organizationId,
                    (string) $customer['promo_code']
                );

                if ($promoCode === null) {
                    throw new DomainRuleViolation(
                        'Promo code not found.',
                        'PROMO_CODE_NOT_FOUND',
                    );
                }

                $promoEvaluation = $this->promos->evaluateForCart($promoCode, $cart);

                if (!$promoEvaluation->valid) {
                    throw new DomainRuleViolation(
                        sprintf('Promo code cannot be applied: %s.', (string) $promoEvaluation->rejectionReason),
                        'PROMO_CODE_REJECTED',
                    );
                }
            }

            $subtotalAmount = (int) ($cart->total_amount ?? 0);
            $discountAmount = $promoEvaluation?->discount->minorUnits() ?? 0;
            // Р”РІРѕР№РЅР°СЏ СЃС‚СЂР°С…РѕРІРєР° РѕС‚ РѕС‚СЂРёС†Р°С‚РµР»СЊРЅРѕРіРѕ РёС‚РѕРіР°: evaluator СѓР¶Рµ СЂРµР¶РµС‚
            // СЃРєРёРґРєСѓ РїРѕ РїРѕРґС‹С‚РѕРіСѓ РїСЂРёРјРµРЅРёРјС‹С… СЃС‚СЂРѕРє, РЅРѕ scope-СЃС‚СЂРѕРєРё РјРѕРіСѓС‚ Р±С‹С‚СЊ
            // РјРµРЅСЊС€Рµ РІСЃРµР№ РєРѕСЂР·РёРЅС‹, Р° total_amount РєРѕРіРґР°-РЅРёР±СѓРґСЊ РЅР°С‡РЅС‘С‚ РІРєР»СЋС‡Р°С‚СЊ
            // СЃР±РѕСЂС‹ вЂ” С‚РѕРіРґР° subtotal Рё total СЂР°Р·РѕР№РґСѓС‚СЃСЏ.
            $discountAmount = min($discountAmount, $subtotalAmount);

            // Р‘РѕРЅСѓСЃС‹. РџРѕРєСѓРїР°С‚РµР»СЊ РЅР°Р·С‹РІР°РµС‚ СЃСѓРјРјСѓ, Р»РёРјРёС‚ СЃС‡РёС‚Р°РµС‚ `BonusPolicy`:
            // 30% РѕС‚ РїРѕРґС‹С‚РѕРіР°, РЅРµ Р±РѕР»СЊС€Рµ РѕСЃС‚Р°С‚РєР° РїРѕСЃР»Рµ СЃРєРёРґРєРё Рё РЅРµ Р±РѕР»СЊС€Рµ
            // Р±Р°Р»Р°РЅСЃР°. Р РµС€РµРЅРёРµ Рѕ Р»РёРјРёС‚Рµ Р¶РёРІС‘С‚ РІ РјРѕРґСѓР»Рµ Р»РѕСЏР»СЊРЅРѕСЃС‚Рё вЂ” Р·РґРµСЃСЊ РµРіРѕ
            // РЅРµ РїРµСЂРµСЃС‡РёС‚С‹РІР°СЋС‚, РёРЅР°С‡Рµ В«30%В» СЃСѓС‰РµСЃС‚РІРѕРІР°Р»Рѕ Р±С‹ РІ РґРІСѓС… РјРµСЃС‚Р°С….
            $currency = (string) ($cart->currency ?? 'RUB');
            $requestedBonus = (int) ($customer['bonus_amount'] ?? 0);
            $bonusCap = $this->bonus->spendableFor(
                $buyerId,
                Money::of($subtotalAmount, $currency),
                Money::of($discountAmount, $currency),
            );

            // РћС‚РєР°Р·, Р° РЅРµ С‚РёС…РѕРµ СѓСЂРµР·Р°РЅРёРµ. РњРѕР»С‡Р°Р»РёРІРѕРµ СѓСЂРµР·Р°РЅРёРµ РёР·РјРµРЅРёР»Рѕ Р±С‹ С†РµРЅСѓ,
            // РєРѕС‚РѕСЂСѓСЋ РїРѕРєСѓРїР°С‚РµР»СЊ РїРѕРґС‚РІРµСЂРґРёР»: РѕРЅ РїСЂРѕСЃРёР» СЃРїРёСЃР°С‚СЊ 600, Р° Р·Р°РїР»Р°С‚РёР»
            // Р±С‹ РєР°Рє Р·Р° 300. РћС‚РєР°Р· РІРѕР·РІСЂР°С‰Р°РµС‚ РµРіРѕ Рє РІС‹Р±РѕСЂСѓ СЃ Р°РєС‚СѓР°Р»СЊРЅС‹Рј Р»РёРјРёС‚РѕРј.
            if ($requestedBonus > $bonusCap->minorUnits()) {
                throw new DomainRuleViolation(
                    sprintf(
                        'Cannot spend %d bonus minor units: the limit for this order is %d.',
                        $requestedBonus,
                        $bonusCap->minorUnits()
                    ),
                    'BONUS_LIMIT_EXCEEDED',
                    [
                        'requested' => $requestedBonus,
                        'available' => $bonusCap->minorUnits(),
                        'subtotal_amount' => $subtotalAmount,
                    ],
                );
            }

            // РС‚РѕРі СЃС‡РёС‚Р°РµС‚ `OrderTotals`, Р° РЅРµ Р°СЂРёС„РјРµС‚РёРєР° РЅР° РјРµСЃС‚Рµ. Р­С‚Рѕ РЅРµ
            // РєРѕСЃРјРµС‚РёРєР°: `total = subtotal - discount - bonus + fee` вЂ” РёРЅРІР°СЂРёР°РЅС‚,
            // РїСЂРѕРґСѓР±Р»РёСЂРѕРІР°РЅРЅС‹Р№ CHECK'РѕРј `ck_orders_amounts`, Рё РґРѕ С„Р°Р·С‹ 2b РѕРЅ Р±С‹Р»
            // Р·Р°РїРёСЃР°РЅ Р·РґРµСЃСЊ РўР Р•РўР¬РРњ СЃРїРѕСЃРѕР±РѕРј (РїРѕСЃР»Рµ `OrderPricing` Рё `OrderTotals`).
            // РљР»Р°РјРїС‹ Р¶РёРІСѓС‚ РІ РѕР±СЉРµРєС‚Рµ, РїРѕСЌС‚РѕРјСѓ В«Р±РѕРЅСѓСЃРѕРІ Р±РѕР»СЊС€Рµ, С‡РµРј Р·Р°РєР°Р·В» Р·РґРµСЃСЊ
            // РЅРµ РјРѕР¶РµС‚ РґР°С‚СЊ РѕС‚СЂРёС†Р°С‚РµР»СЊРЅС‹Р№ РёС‚РѕРі.
            $totals = OrderTotals::of(
                Money::of($subtotalAmount, $currency),
                Money::of($discountAmount, $currency),
                Money::zero($currency),
                $promoEvaluation,
                Money::of($requestedBonus, $currency),
            );

            $discountAmount = $totals->discount()->minorUnits();
            $bonusAmount = $totals->bonus()->minorUnits();
            $totalAmount = $totals->total()->minorUnits();

            $order = Order::create([
                'organization_id' => $organizationId,
                // Р’Р»Р°РґРµР»РµС† Р·Р°РєР°Р·Р° вЂ” РІС‹С‡РёСЃР»РµРЅ РІС‹С€Рµ (`resolveBuyerId`): СЃРѕС‚СЂСѓРґРЅРёРє,
                // РІРѕС€РµРґС€РёР№ РїРѕРєСѓРїР°С‚РµР»СЊ РёР»Рё Р°РєРєР°СѓРЅС‚, Р·Р°РІРµРґС‘РЅРЅС‹Р№ РїРѕ Р°РґСЂРµСЃСѓ Р·Р°РєР°Р·Р°.
                // Р‘РµР· РІР»Р°РґРµР»СЊС†Р° Р·Р°РєР°Р· РЅРµРІРёРґРёРј РІ `GET /orders` (СЃРїРёСЃРѕРє СЃРєРѕСѓРїРёС‚СЃСЏ
                // РїРѕ РІР»Р°РґРµР»СЊС†Сѓ) Рё РЅРµРґРѕСЃС‚СѓРїРµРЅ РїРѕРґРґРµСЂР¶РєРµ РїРѕРєСѓРїР°С‚РµР»СЏ.
                'user_id' => $buyerId,
                'status' => OrderStateMachine::PENDING,
                'payment_status' => PaymentStateMachine::PENDING,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                // РЎРїРёСЃР°РЅРѕ Р±РѕРЅСѓСЃР°РјРё вЂ” РѕС‚РґРµР»СЊРЅРѕР№ РєРѕР»РѕРЅРєРѕР№, Р° РЅРµ РІРЅСѓС‚СЂРё СЃРєРёРґРєРё:
                // РїРѕРєСѓРїР°С‚РµР»СЊ РІРёРґРёС‚ СЃС‚СЂРѕРєСѓ, Р° СЂР°Р·Р±РѕСЂ Р·Р°РєР°Р·Р° РѕС‚Р»РёС‡Р°РµС‚ РєРѕРґ РѕС‚
                // Р»РѕСЏР»СЊРЅРѕСЃС‚Рё (СЃРј. `OrderTotals`).
                'bonus_amount' => $bonusAmount,
                'fee_amount' => 0,
                'total_amount' => $totalAmount,
                'currency' => $cart->currency ?? 'RUB',
                // A6-С†РµРїРѕС‡РєР° В«Р·Р°РєР°Р· в†’ РѕРїР»Р°С‚Р° в†’ Р±РёР»РµС‚В»: Р·Р°РєР°Р· РїРѕРјРЅРёС‚ РєРѕСЂР·РёРЅСѓ,
                // СЃРµР°РЅСЃ Рё СЃРѕР±С‹С‚РёРµ, РёР· РєРѕС‚РѕСЂС‹С… РІС‹СЂРѕСЃ. PaymentService РёСЃРїРѕР»СЊР·СѓРµС‚
                // orders.cart_id РґР»СЏ РІР°Р»РёРґР°С†РёРё С…РѕР»РґРѕРІ, Р° TicketService Р±РµСЂС‘С‚
                // session_id/event_id (NOT NULL РІ С‚Р°Р±Р»РёС†Рµ tickets) РёРјРµРЅРЅРѕ РѕС‚СЃСЋРґР°.
                // Р‘РµР· СЌС‚РёС… РїРѕР»РµР№ Р±РёР»РµС‚С‹ РЅРµ РІС‹РїСѓСЃРєР°Р»РёСЃСЊ РЅРёРєРѕРіРґР° вЂ” РїСЂРѕРІРµСЂРµРЅРѕ РїРѕ РєРѕРґСѓ.
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

            // РЎРїРёСЃР°РЅРёРµ вЂ” РџРћРЎР›Р• СЃРѕР·РґР°РЅРёСЏ Р·Р°РєР°Р·Р°, РїРѕС‚РѕРјСѓ С‡С‚Рѕ СЃС‚СЂРѕРєР° Р¶СѓСЂРЅР°Р»Р°
            // СЃСЃС‹Р»Р°РµС‚СЃСЏ РЅР° `order_id` (FK `fk_bonus_transactions_order`), Рё РІ Р­РўРћР™
            // Р–Р• С‚СЂР°РЅР·Р°РєС†РёРё. РћС‚СЃСЋРґР° РґРІР° СЃРІРѕР№СЃС‚РІР°, РєРѕС‚РѕСЂС‹Рµ Рё РЅСѓР¶РЅС‹: РѕС‚РєР°С‚
            // РѕС„РѕСЂРјР»РµРЅРёСЏ РѕС‚РєР°С‚С‹РІР°РµС‚ СЃРїРёСЃР°РЅРёРµ (В«Р·Р°РєР°Р· РЅРµ СЃРѕР·РґР°Р»СЃСЏ, Р° Р±РѕРЅСѓСЃС‹
            // СѓС€Р»РёВ» РЅРµРІРѕР·РјРѕР¶РЅРѕ), Р° Р·Р°РєР°Р· Р±РµР· СЃРїРёСЃР°РЅРёСЏ РЅРµРІРѕР·РјРѕР¶РµРЅ РЅР°РѕР±РѕСЂРѕС‚,
            // РїРѕС‚РѕРјСѓ С‡С‚Рѕ `bonus_amount` СѓР¶Рµ Р·Р°РїРёСЃР°РЅ РІС‹С€Рµ РёР· С‚РѕРіРѕ Р¶Рµ СЂР°СЃС‡С‘С‚Р°.
            //
            // Р‘Р°Р»Р°РЅСЃ СѓРјРµРЅСЊС€Р°РµС‚СЃСЏ РЎР•Р™Р§РђРЎ, Р° РЅРµ РїСЂРё РѕРїР»Р°С‚Рµ. РўР°Рє Р±Р°Р»Р°РЅСЃ РѕСЃС‚Р°С‘С‚СЃСЏ
            // РµРґРёРЅСЃС‚РІРµРЅРЅС‹Рј РёСЃС‚РѕС‡РЅРёРєРѕРј РїСЂР°РІРґС‹: РѕРЅ Р¶Рµ РїРѕРєР°Р·С‹РІР°РµС‚СЃСЏ РІ С€Р°РїРєРµ, Рё РїРѕ
            // РЅРµРјСѓ Р¶Рµ СЃС‡РёС‚Р°РµС‚СЃСЏ Р»РёРјРёС‚ СЃР»РµРґСѓСЋС‰РµРіРѕ Р·Р°РєР°Р·Р°. Р•СЃР»Рё Р±С‹ СЃРїРёСЃР°РЅРёРµ
            // РѕС‚РєР»Р°РґС‹РІР°Р»РѕСЃСЊ РґРѕ `markPaid`, РѕРґРёРЅ Рё С‚РѕС‚ Р¶Рµ Р±Р°Р»Р°РЅСЃ РѕР±РµСЃРїРµС‡РёР» Р±С‹
            // СЃРїРёСЃР°РЅРёРµ РІ РґРІСѓС… РЅРµР·Р°РєСЂС‹С‚С‹С… Р·Р°РєР°Р·Р°С…. РќРµРѕРїР»Р°С‡РµРЅРЅС‹Р№ Р·Р°РєР°Р· РѕСЃРІРѕР±РѕР¶РґР°РµС‚
            // Р±РѕРЅСѓСЃС‹ С‚Р°Рє Р¶Рµ, РєР°Рє РјРµСЃС‚Р°, вЂ” `BonusService::refundForOrder()`.
            if ($bonusAmount > 0) {
                $this->bonus->spendForOrder(
                    $order,
                    Money::of($bonusAmount, $currency),
                    Money::of($subtotalAmount, $currency),
                    Money::of($discountAmount, $currency),
                );
            }

            // Р Р°СЃС…РѕРґ РєРѕРґР° вЂ” РџРћРЎР›Р• СЃРѕР·РґР°РЅРёСЏ Р·Р°РєР°Р·Р°: redeem() РїРёС€РµС‚ redemption СЃРѕ
            // СЃСЃС‹Р»РєРѕР№ РЅР° order_id. РЎС‡С‘С‚С‡РёРє РґРІРёРіР°РµС‚СЃСЏ СѓСЃР»РѕРІРЅС‹Рј UPDATE (СЃРј.
            // PromoCodeService::redeem), Рё РІРµСЃСЊ Р±Р»РѕРє вЂ” РІ СЌС‚РѕР№ Р¶Рµ С‚СЂР°РЅР·Р°РєС†РёРё:
            // РѕС‚РєР°С‚ Р·Р°РєР°Р·Р° РѕС‚РєР°С‚С‹РІР°РµС‚ Рё РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ РєРѕРґР°. РђРЅРѕРЅРёРјРЅР°СЏ РєРѕСЂР·РёРЅР°
            // (user_id null) Р»РёРјРёС‚ РЅР° РїРѕРєСѓРїР°С‚РµР»СЏ РЅРµ СЂР°СЃС…РѕРґСѓРµС‚ вЂ” per_user_limit
            // СЃС‡РёС‚Р°РµС‚СЃСЏ РїРѕ Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅРЅС‹Рј РїРѕР»СЊР·РѕРІР°С‚РµР»СЏРј.
            if ($promoCode !== null && $promoEvaluation !== null) {
                $this->promos->redeem($promoCode, $order, $promoEvaluation, $cart->user_id === null ? null : (int) $cart->user_id);
            }

            // Р РµС„РµСЂР°Р»СЊРЅР°СЏ Р°С‚СЂРёР±СѓС†РёСЏ (С„Р°Р·Р° 4) вЂ” РџРћРЎР›Р• СЃРѕР·РґР°РЅРёСЏ Р·Р°РєР°Р·Р°: СЃС‚СЂРѕРєР°
            // РїСЂРёРіР»Р°С€РµРЅРёСЏ РєР»СЋС‡СѓРµС‚СЃСЏ Р°РґСЂРµСЃРѕРј Р·Р°РєР°Р·Р° (`customer_email`) Рё
            // СѓС‚РѕС‡РЅСЏРµС‚СЃСЏ Р°РєРєР°СѓРЅС‚РѕРј (`resolveBuyerId()` РІС‹С€Рµ). Р’ С‚РѕР№ Р¶Рµ
            // С‚СЂР°РЅР·Р°РєС†РёРё, С‡С‚Рѕ Рё Р·Р°РєР°Р·: РѕС‚РєР°С‚ РѕС„РѕСЂРјР»РµРЅРёСЏ РѕС‚РєР°С‚С‹РІР°РµС‚ Рё
            // РїСЂРёРіР»Р°С€РµРЅРёРµ. РќР°РіСЂР°РґР° Р·РґРµСЃСЊ РќР• РІС‹РїР»Р°С‡РёРІР°РµС‚СЃСЏ вЂ” РѕРЅР° Р¶РґС‘С‚ РѕРїР»Р°С‚С‹
            // (`OrderReferralObserver` в†’ `RewardReferralJob`), РїРѕС‚РѕРјСѓ С‡С‚Рѕ
            // В«РїРµСЂРІР°СЏ РѕРїР»Р°С‚Р° РґСЂСѓРіР°В» вЂ” РґРµРЅРµР¶РЅРѕРµ СЃРѕР±С‹С‚РёРµ, Р° РЅРµ РЅР°РјРµСЂРµРЅРёРµ.
            //
            // РќРµРёР·РІРµСЃС‚РЅС‹Р№ РёР»Рё СѓСЃС‚Р°СЂРµРІС€РёР№ РєРѕРґ вЂ” РјРѕР»С‡Р° (СЃРј. `ReferralService`):
            // СЃСЃС‹Р»РєР° РЅРµ РїСЂРѕРјРѕРєРѕРґ, Рё Р»РѕРјР°С‚СЊ РёР·-Р·Р° РЅРµС‘ РїРѕРєСѓРїРєСѓ РЅРµР·Р°С‡РµРј.
            if (!empty($customer['referral_code'])) {
                $this->referrals->attachToOrder($order, (string) $customer['referral_code']);
            }

            return [
                'cart_id' => $cart->id,
                'order_id' => $order->public_id,
                // `session_id` Р±РµСЂС‘С‚СЃСЏ РёР· РєРѕСЂР·РёРЅС‹, Р° РЅРµ РёР· РїР°СЂР°РјРµС‚СЂР° РІС…РѕРґР°:
                // РІС‚РѕСЂРѕР№ РІС…РѕРґ (`checkoutByPublicId`) РїР°СЂР°РјРµС‚СЂР° РЅРµ РёРјРµРµС‚.
                'session_id' => (string) $cart->session_id,
                'items' => $cart->items->map(fn($item) => [
                    'inventory_item_id' => $item->inventory_item_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ])->toArray(),
                'total_amount' => $totalAmount,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'bonus_amount' => $bonusAmount,
                // РџРѕСЂСЏРґРѕРє РїРѕРёСЃРєР° РІР°Р»СЋС‚С‹: С†РµРЅР°.attr РµСЃС‚СЊ Рё Сѓ РёРЅРІРµРЅС‚Р°СЂСЏ. РЈ CARTS
                // РєРѕР»РѕРЅРєРё currency РќР•Рў ((INVENTED_FIELDS Cart) вЂ” РѕРЅР° Р±С‹Р»Р° Р±С‹
                // null, Рё РІРёС‚СЂРёРЅР° РјРѕРіР»Р° Р±С‹ РїРѕРєР°Р·Р°С‚СЊ РїСѓСЃС‚СѓСЋ РІР°Р»СЋС‚Сѓ.
                'currency' => (string) ($order->currency ?? 'RUB'),
            ];
    }

    /**
     * Р’РѕСЃСЃС‚Р°РЅРѕРІРёС‚СЊ РѕС‚РІРµС‚ СѓР¶Рµ РћР¤РћР РњР›Р•РќРќРћР™ РєРѕСЂР·РёРЅС‹ (D-8/S8).
     *
     * РћС‚РІРµС‚ СЃРѕР±РёСЂР°РµС‚СЃСЏ РёР· Р—РђРљРђР—Рђ, Р° РЅРµ РёР· РєРѕСЂР·РёРЅС‹: Сѓ РєРѕРЅРІРµСЂС‚РёСЂРѕРІР°РЅРЅРѕР№ РєРѕСЂР·РёРЅС‹
     * РїРѕР·РёС†РёРё СѓР¶Рµ РїРµСЂРµРµС…Р°Р»Рё РІ order_items, Рё РёС… РїРµСЂРµС‡РµРЅСЊ РёСЃС‚РѕС‡РЅРёРє РёСЃС‚РёРЅС‹ вЂ”
     * Р·Р°РєР°Р·. Р’РѕР·РІСЂР°С‰Р°РµС‚СЃСЏ СЃ С„Р»Р°РіРѕРј `replayed: true`, С‡С‚РѕР±С‹ РѕС‚Р»РёС‡РёС‚СЊ РїРѕРІС‚РѕСЂ
     * РѕС‚ РїРµСЂРІРёС‡РЅРѕРіРѕ РѕС„РѕСЂРјР»РµРЅРёСЏ (РЅР°С€Рµ РІСЂРµРјСЏ вЂ” РЅР°Р±Р»СЋРґР°РµРјРѕСЃС‚СЊ, Р° РЅРµ РєРѕРЅС‚СЂР°РєС‚:
     * РєР»СЋС‡Рё РѕС‚РІРµС‚Р° СЃРѕРІРїР°РґР°СЋС‚ СЃ РѕР±С‹С‡РЅС‹Рј СѓСЃРїРµС…РѕРј).
     */
    private function replayConvertedOrder(string $sessionId, string $token): ?array
    {
        $cartId = DB::table('carts')
            ->where('session_id', $sessionId)
            ->where('cart_token', $token)
            ->where('status', CartStatus::CONVERTED)
            ->orderByDesc('id')
            ->value('id');

        if ($cartId === null) {
            return null;
        }

        // Replay гостевого checkout ищет заказ по `cart_id` — без tenant-контекста.
        $order = app(OrganizationContext::class)->withoutScope(
            fn () => Order::query()
                ->where('cart_id', $cartId)
                ->orderBy('id')
                ->with('items')
                ->first()
        );

        if ($order === null) {
            return null;
        }

        return [
            'cart_id' => (int) $cartId,
            'order_id' => (string) $order->public_id,
            'session_id' => (string) $sessionId,
            'items' => $order->items
                ->map(fn (\Nabilet\Modules\Orders\Models\OrderItem $item) => [
                    'inventory_item_id' => (int) $item->inventory_item_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (int) $item->unit_price,
                    'total_price' => (int) $item->total_price,
                ])
                ->toArray(),
            'total_amount' => (int) $order->total_amount,
            'subtotal_amount' => (int) $order->subtotal_amount,
            'discount_amount' => (int) $order->discount_amount,
            'bonus_amount' => (int) ($order->bonus_amount ?? 0),
            'currency' => (string) ($order->currency ?? 'RUB'),
            'replayed' => true,
        ];
    }
}
