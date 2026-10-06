<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Http\Controllers;

use Nabilet\Modules\Cart\Http\Resources\CartResource;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Services\CartCheckoutService;
use Nabilet\Modules\Cart\Services\CartItemService;
use Nabilet\Modules\Cart\Support\CartToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Cart endpoints.
 *
 * There is deliberately no `try`/`catch` and no `response()->json(['error' => …])`
 * anywhere in this controller. Every failure — a missing `session_id`, an exhausted
 * inventory item, an expired cart — is raised as an `AppError` (by the validator or by
 * `CartItemService`) and rendered by `ApiExceptionRenderer` into the §66 envelope. Catching
 * and re-shaping here is what produced bodies like `{"error":"session_id required"}`:
 * a flat string with no `code` to branch on and no `request_id` to correlate a support
 * ticket with a log line.
 *
 * D5: корзина принадлежит покупателю, а не сеансу. Идентификатор покупателя —
 * заголовок `X-Cart-Token` (гостевой UUID браузера). Если клиент его ещё не имеет,
 * сервер генерирует токен и возвращает в теле (`cart_token`) и в заголовке ответа —
 * клиент сохраняет его (localStorage) и шлёт дальше. Так два параллельных покупателя
 * одного сеанса работают в РАЗНЫХ корзинах.
 */
class CartController extends Controller
{
    public function __construct(
        protected CartItemService $cartItems,
        protected CartCheckoutService $cartCheckout,
    ) {}

    /**
     * Разобрать/выдать токен покупателя. Возвращает [token, isNew].
     */
    private function resolveToken(Request $request): array
    {
        $token = CartToken::fromRequest($request);

        if ($token !== null) {
            return [$token, false];
        }

        // Пользователь авторизован — корзиной владеет он; стабильный токен привязан к аккаунту.
        if (($user = $request->user()) !== null) {
            return ['u' . $user->id, false];
        }

        return [CartToken::generate(), true];
    }

    private function withTokenHeader(JsonResponse $response, string $token, bool $isNew): JsonResponse
    {
        $response->headers->set(CartToken::HEADER, $token);

        if ($isNew) {
            $payload = $response->getData(true);
            $payload['cart_token'] = $token;
            $response->setData($payload);
        }

        return $response;
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['bail', 'required', 'integer'],
        ]);

        [$token, $isNew] = $this->resolveToken($request);

        $cart = Cart::query()
            ->where('session_id', $validated['session_id'])
            ->where('status', 'active')
            ->where('cart_token', $token)
            // `seat.row.sector` нужны `CartItemResource` целиком: он отдаёт
            // покупателю «Ряд N · Сектор». Без этой цепочки каждая позиция
            // догружала связи по одной (N+1) — а до правки ресурса запрос падал
            // с 500 ещё раньше, на `whenLoaded()` у модели.
            ->with(['items.inventoryItem', 'items.inventoryItem.seat.row.sector'])
            ->orderBy('id')
            ->first();

        return $this->withTokenHeader(response()->json([
            'data' => $cart ? new CartResource($cart) : null,
        ], 200), $token, $isNew);
    }

    public function addItem(Request $request): JsonResponse
    {
        // Лимит билетов на заказ — ИЗ КОНФИГА, а не литералом.
        //
        // Здесь стояло `max:10`, и это было единственное место, где лимит
        // реально применялся. При этом в проекте существовали ещё два его
        // объявления, и оба не работали:
        //   * `NABILET_MAX_SEATS_PER_ORDER=8` в `.env` — не читал никто;
        //   * `nabilet.checkout.max_items_per_order` (CHECKOUT_MAX_ITEMS) —
        //     ключ конфига не читался вовсе.
        // Оператор правил «anti-scalping guard» и не получал никакого эффекта:
        // в настройках стояло 8, а система пропускала 10.
        //
        // Теперь источник один — config/nabilet.php → CHECKOUT_MAX_ITEMS.
        // То же число уходит витрине в `meta.max_tickets_per_order`
        // (InventoryController::index), чтобы клиент не держал свою копию.
        $maxPerOrder = max(1, (int) config('nabilet.checkout.max_items_per_order', 10));

        $validated = $request->validate([
            // `bail` + `integer` are load-bearing here, not decoration. `sessions.id`
            // and `inventory_items.id` are BIGINT, and MySQL does not reject a
            // comparison against a string — it coerces it and warns. Measured on
            // MySQL 8.4.11:
            //
            //   WHERE id = 'nope'  -> 0 rows, warning 1292 "Truncated incorrect
            //                         DOUBLE value" (a warning, not an error)
            //   WHERE id = '1abc'  -> 1 row matched: the string coerces to 1
            //
            // The second line is the one that matters. Without `bail`, `exists` still
            // runs after `integer` has failed and reports a row for `'1abc'`, so the
            // rejection is attributed to the wrong rule after a pointless query.
            //
            // `bail` is the part that prevents it: Laravel only stops evaluating an
            // attribute's remaining rules when `bail` is present, so `integer` failing
            // is not enough on its own — `exists` would still run.
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
            'inventory_item_id' => ['bail', 'required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:' . $maxPerOrder],
        ]);

        [$token, $isNew] = $this->resolveToken($request);

        // `CartItemService::addItem()` declares `string $sessionId`, and this file is under
        // `strict_types=1`, so an integer `session_id` in the JSON body would be a
        // TypeError rather than a cast. The spec types `session_id` as a string, but a
        // client that sends a number must not get a 500.
        $cartItem = $this->cartItems->addItem(
            (string) $validated['session_id'],
            (int) $validated['inventory_item_id'],
            (int) $validated['quantity'],
            $token,
        );

        $cart = $cartItem->cart()->with('items')->first();

        return $this->withTokenHeader(response()->json([
            'message' => 'Item added to cart successfully',
            'data' => $cartItem,
            'cart' => $cart ? [
                'cart_id' => $cart->id,
                'total_amount' => $cart->total_amount,
                'currency' => $cart->currency,
                'expires_at' => $cart->expires_at?->toIso8601String(),
            ] : null,
        ], 201), $token, $isNew);
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['bail', 'required', 'integer'],
        ]);

        [$token, $isNew] = $this->resolveToken($request);

        $this->cartItems->removeItem((string) $validated['session_id'], $itemId, $token);

        return $this->withTokenHeader(response()->json([
            'message' => 'Item removed from cart successfully',
        ]), $token, $isNew);
    }

    public function extend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast — see `addItem()`.
            'session_id' => ['bail', 'required', 'integer'],
        ]);

        $token = CartToken::fromRequest($request);

        $result = $this->cartItems->extendHold((string) $validated['session_id'], $token);

        return response()->json([
            'message' => 'Hold extended',
            'data' => $result,
        ], 200);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast — see `addItem()`.
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['required', 'email:rfc', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'promo_code' => ['nullable', 'string', 'max:64'],
        ]);

        [$token, $isNew] = $this->resolveToken($request);

        $checkoutResult = $this->cartCheckout->checkout((string) $validated['session_id'], [
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'promo_code' => $validated['promo_code'] ?? null,
        ], $token);

        return $this->withTokenHeader(response()->json([
            'message' => 'Checkout successful',
            'data' => $checkoutResult,
        ]), $token, $isNew);
    }
}
