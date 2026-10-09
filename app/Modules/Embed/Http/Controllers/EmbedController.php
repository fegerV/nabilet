<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Cart\Http\Resources\CartResource;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Services\CartCheckoutService;
use Nabilet\Modules\Cart\Services\CartItemService;
use Nabilet\Modules\Cart\Services\CartService;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Embed\Services\EmbedAccessService;
use Nabilet\Modules\Events\Http\Resources\EventResource;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Orders\Http\Resources\OrderResource;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;

/**
 * The seven `/api/v1/embed/*` operations the contract declares.
 *
 * These are the only endpoints in the application that are authorized by
 * something other than a user session: an embed widget runs on a third party's
 * site, in a browser that has no NABILET account and no NABILET cookie. The
 * caller is identified by a capability pair — the `embed_token` query parameter
 * (a scoped `api_keys` row) and the `Origin` header — and every check lives in
 * `EmbedAccessService`, which this controller calls first in every method.
 *
 * NO BUSINESS LOGIC HERE, DELIBERATELY.
 *   Seats are reserved, carts are keyed and orders are created by the same
 *   services the storefront uses (`CartService`, `CartItemService`,
 *   `CartCheckoutService`, `PaymentService`). A second implementation of
 *   "reserve a seat" written for the embed surface would be a second place for
 *   the seat-hold invariant to be broken, and the first one is already hard
 *   enough. What this class adds is exactly two things: the §18 gate, and the
 *   contract's response shape.
 *
 * WHY THE RESPONSES ARE MAPPED BY HAND INSTEAD OF REUSING THE MODULE RESOURCES.
 *   `CartResource` and `EventResource` are reused where they already match the
 *   contract. The session and payment payloads are not, because the module
 *   resources describe a different surface: `SessionResource` omits
 *   `event_id`/`venue_id`/`hall_id`/`schema_version_id`, which `Session` requires,
 *   and `PaymentResource` omits `order_id`, which `Payment` names. Stretching a
 *   shared resource to satisfy both shapes is how one surface's response starts
 *   changing when the other is edited.
 *
 * THE CHECKOUT TOKEN HAS TWO NAMES, ON PURPOSE.
 *   The contract calls the guest capability `checkout_token` and passes it in
 *   `X-Checkout-Token`. The storefront has always called the same value
 *   `cart_token` and passed it in `X-Cart-Token` — it is one column,
 *   `carts.cart_token`. The embed surface accepts either header and returns the
 *   value under both names, so a widget embedded inside the storefront shares one
 *   cart with the page around it instead of creating a second one.
 */
final class EmbedController extends Controller
{
    /** The contract's name for the guest capability header. */
    private const CHECKOUT_HEADER = 'X-Checkout-Token';

    /** The contract's name for the replay-protection header. */
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function __construct(
        private readonly EmbedAccessService $access,
        private readonly CartService $carts,
        private readonly CartItemService $cartItems,
        private readonly CartCheckoutService $checkout,
        private readonly PaymentService $payments,
    ) {}

    // ── Catalogue ────────────────────────────────────────────────────────────

    /**
     * `GET /api/v1/embed/events/{event}` — the event page inside the widget.
     */
    public function showEvent(Request $request, int $event): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $model = $this->access->event($event, $organizationId);

        // `sessions.venue` is eager-loaded although `EventResource` reads it
        // through `sessions`: without it the resource lazy-loads one venue per
        // session. `EventController::show()` loads `sessions` alone, and this is
        // the same response with fewer queries, not a different one.
        $model->load(['category', 'translations', 'sessions.venue']);

        return response()->json(['data' => new EventResource($model)]);
    }

    /**
     * `GET /api/v1/embed/events/{event}/sessions` — the schedule inside the widget.
     */
    public function listEventSessions(Request $request, int $event): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $model = $this->access->event($event, $organizationId);

        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));

        $sessions = Session::query()
            ->where('event_id', $model->id)
            ->orderBy('starts_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $sessions->getCollection()
                ->map(fn (Session $session): array => $this->sessionPayload($session))
                ->all(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    /**
     * `GET /api/v1/embed/sessions/{session}/seatmap` — geometry plus inventory.
     *
     * The whole hall is returned, not a page of it: a seat map that arrives in
     * pages is not a seat map. `InventoryItem` rows are the same rows
     * `GET /api/v1/inventory?session_id=` returns, so a widget and the storefront
     * see the same availability — including `available_quantity`, which is what
     * makes a half-sold standing zone render correctly.
     */
    public function seatmap(Request $request, int $session): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $model = $this->access->session($session, $organizationId);

        $model->load('schemaVersion');

        $inventory = InventoryItem::query()
            ->where('session_id', $model->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'session' => $this->sessionPayload($model),
            'schema' => $this->schemaPayload($model->schemaVersion),
            'inventory' => $inventory
                ->map(fn (InventoryItem $item): array => [
                    'id' => $item->public_id,
                    'session_id' => $item->session_id,
                    'type' => $item->type,
                    'seat_id' => $item->seat_id,
                    'standing_zone_id' => $item->standing_zone_id,
                    'price_amount' => $item->price_amount,
                    'currency' => $item->currency,
                    'capacity' => $item->capacity,
                    'available_quantity' => $item->available_quantity,
                    'status' => $item->status,
                ])
                ->all(),
        ]);
    }

    // ── Cart ─────────────────────────────────────────────────────────────────

    /**
     * `POST /api/v1/embed/carts` — open (or reopen) the guest's cart for a session.
     *
     * 201 with `checkout_token` required by the contract's `CreateCartResponse`.
     * The token is echoed back under both names and also in the `X-Cart-Token`
     * response header, so the widget can store whichever it was built against.
     *
     * The session is resolved through `EmbedAccessService`, which is what refuses
     * a session of another organization and a session of a non-published event.
     */
    public function createCart(Request $request): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $validated = $request->validate([
            // No `integer` rule: `carts.session_id` is a string column and
            // `Session::findOrFailBySessionId()` accepts the ULID `public_id` as
            // well as the numeric key. The lookup itself is fail-closed — see
            // `EmbedAccessService::findByReference()`.
            'session_id' => ['bail', 'required', 'string'],
        ]);

        $session = $this->access->session($validated['session_id'], $organizationId);

        $token = $this->checkoutToken($request) ?? CartToken::generate();

        $cart = $this->carts->getOrCreateCart((string) $session->id, $token);
        $cart->load('items.inventoryItem');

        return response()
            ->json([
                'data' => new CartResource($cart),
                'checkout_token' => $token,
            ], 201)
            ->header(CartToken::HEADER, $token);
    }

    /**
     * `POST /api/v1/embed/carts/{cart}/items` — reserve a seat.
     *
     * The session is NOT in the body: it comes from the cart, which is the only
     * place that knows it. That is also why the cart is resolved before the body
     * is validated — a caller who does not hold the cart learns nothing about it.
     */
    public function addCartItem(Request $request, string $cart): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $token = $this->requireCheckoutToken($request);

        $model = $this->access->cart($cart, $token, $organizationId);

        // Same source as `CartController::addItem()`: config/nabilet.php →
        // CHECKOUT_MAX_ITEMS. A literal here would be the second copy of the
        // anti-scalping limit, and the one that goes stale.
        $maxPerOrder = max(1, (int) config('nabilet.checkout.max_items_per_order', 10));

        $validated = $request->validate([
            'inventory_item_id' => ['bail', 'required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:' . $maxPerOrder],
        ]);

        $this->cartItems->addItem(
            (string) $model->session_id,
            (int) $validated['inventory_item_id'],
            (int) $validated['quantity'],
            $token,
        );

        $fresh = Cart::query()->with('items.inventoryItem')->find($model->id);

        return response()->json(['data' => new CartResource($fresh)], 201);
    }

    // ── Checkout ─────────────────────────────────────────────────────────────

    /**
     * `POST /api/v1/embed/orders` — turn the cart into an order.
     *
     * `cart_id` is the ULID `public_id` the create-cart response returned (the
     * numeric key is accepted too — see `EmbedAccessService::findByReference()`).
     */
    public function createOrder(Request $request): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $token = $this->requireCheckoutToken($request);

        $validated = $request->validate([
            'cart_id' => ['bail', 'required'],
            'customer_email' => ['required', 'email:rfc', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'promo_code' => ['nullable', 'string', 'max:64'],
        ]);

        // An array/object in `cart_id` is a client error, not a 500 on a cast —
        // the same guard `PaymentController::store()` carries for `order_id`.
        if (! is_scalar($validated['cart_id'])) {
            throw new ValidationError([
                'cart_id' => ['The cart id must be a string or an integer.'],
            ]);
        }

        $cart = $this->access->cart((string) $validated['cart_id'], $token, $organizationId);

        $result = $this->checkout->checkout((string) $cart->session_id, [
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'promo_code' => $validated['promo_code'] ?? null,
        ], $token);

        $order = Order::query()
            ->with(['items', 'payments'])
            ->where('public_id', $result['order_id'])
            ->firstOrFail();

        return response()->json(['data' => new OrderResource($order)], 201);
    }

    /**
     * `POST /api/v1/embed/orders/{order}/payment` — start paying for the order.
     *
     * §18's fifth rule, "payment capability", is decided here rather than
     * assumed: the order must belong to the token's organization (checked by
     * `EmbedAccessService::order()`) AND must be payable by the caller, which for
     * a guest means holding the cart the order was created from. That is the same
     * rule `PaymentController::mayPay()` applies to the storefront, restated
     * against the checkout token; without it, any valid embed token could open a
     * payment for any order of the same organization.
     *
     * The refusal is 404, not 403: `order_id` alone must not confirm that an order
     * exists — see `NotFoundError`.
     */
    public function createPayment(Request $request, string $order): JsonResponse
    {
        $organizationId = $this->access->authorize($request);

        $token = $this->requireCheckoutToken($request);

        $model = $this->access->order($order, $organizationId);

        if ($model->cart_id === null || ! $this->callerHoldsCart((int) $model->cart_id, $token)) {
            throw new NotFoundError('Order', $order);
        }

        $result = $this->payments->initiatePayment((int) $model->id, [
            'idempotency_key' => $this->idempotencyKey($request),
        ]);

        return response()->json([
            'data' => $this->paymentPayload($result['payment'], $model),
        ], 201);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The guest capability, under whichever name the caller used.
     *
     * `X-Checkout-Token` is the contract's name and is tried first;
     * `X-Cart-Token` is the storefront's and is accepted so a widget embedded in
     * the storefront shares the page's cart. Both are normalised identically
     * (`CartToken::normalize()`: length and alphabet), so neither can smuggle a
     * value the other would have refused.
     */
    private function checkoutToken(Request $request): ?string
    {
        return CartToken::normalize($request->header(self::CHECKOUT_HEADER))
            ?? CartToken::fromRequest($request);
    }

    /**
     * The checkout token, or a 403 explaining that it is required.
     *
     * 403 rather than 422: the token is a capability, exactly like the embed
     * token, and the embed surface answers every capability failure with 403 and a
     * distinct code so the widget can tell "you forgot the token" from "the token
     * is not mine". `EMBED_CHECKOUT_TOKEN_REQUIRED` is deliberately separate from
     * `EMBED_TOKEN_INVALID` — they are two different credentials.
     */
    private function requireCheckoutToken(Request $request): string
    {
        $token = $this->checkoutToken($request);

        if ($token === null) {
            throw new AuthError(
                'Embed cart and checkout requests must present their checkout token.',
                'EMBED_CHECKOUT_TOKEN_REQUIRED',
                403,
                ['header' => self::CHECKOUT_HEADER],
            );
        }

        return $token;
    }

    /** Does the caller hold the cart this order was created from? */
    private function callerHoldsCart(int $cartId, string $token): bool
    {
        return Cart::query()
            ->where('id', $cartId)
            ->where('cart_token', $token)
            ->exists();
    }

    /**
     * The replay-protection key, from the header the contract names or from the
     * body the storefront sends.
     *
     * The contract marks `Idempotency-Key` required on the three embed writes; the
     * application treats it as optional on every write endpoint it has (no route
     * applies the `idempotent` middleware). It is forwarded rather than enforced,
     * because only `PaymentService::initiatePayment()` can actually honour it —
     * enforcing presence where nothing deduplicates would be ceremony. The gap is
     * recorded in `docs/PRODUCTION-READINESS.md` rather than papered over here.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header(self::IDEMPOTENCY_HEADER));

        if ($key === '') {
            $key = trim((string) $request->input('idempotency_key', ''));
        }

        return $key === '' ? null : $key;
    }

    /**
     * The contract's `Session` shape, with dates in ISO 8601.
     *
     * Hand-mapped rather than returned as the model: `Session`'s casts serialise
     * `starts_at` as `Y-m-d H:i:s.u`, while the contract declares `format:
     * date-time`. The storefront tolerates the former; a generated client built
     * from the contract does not.
     */
    private function sessionPayload(Session $session): array
    {
        return [
            'id' => $session->id,
            'public_id' => $session->public_id,
            'event_id' => $session->event_id,
            'venue_id' => $session->venue_id,
            'hall_id' => $session->hall_id,
            'schema_version_id' => $session->schema_version_id,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'sales_start_at' => $session->sales_start_at?->toIso8601String(),
            'sales_end_at' => $session->sales_end_at?->toIso8601String(),
            'timezone' => $session->timezone,
            'status' => $session->status,
        ];
    }

    /**
     * The contract's `HallSchemaVersion` shape.
     *
     * `schema_json` is published as `schema` because that is the name the contract
     * gives the payload — the column and the field differ, and the mapping has to
     * live somewhere. `id` is the ULID `public_id`, matching `SessionResource`'s
     * view of the same row.
     */
    private function schemaPayload(?HallSchemaVersion $schema): ?array
    {
        if ($schema === null) {
            return null;
        }

        return [
            'id' => $schema->public_id,
            'hall_id' => $schema->hall_id,
            'version' => $schema->version,
            'status' => $schema->status,
            'width' => $schema->width,
            'height' => $schema->height,
            'background_url' => $schema->background_url,
            'schema' => $schema->schema_json,
            'published_at' => $schema->published_at?->toIso8601String(),
        ];
    }

    /**
     * The contract's `Payment` shape.
     *
     * `order_id` is the order's ULID, not `payments.order_id` (a BIGINT): the
     * contract types the field as a string and every other identifier it exposes
     * is the external one. `PaymentResource` cannot be reused for this — it
     * carries no `order_id` at all.
     */
    private function paymentPayload(Payment $payment, Order $order): array
    {
        return [
            'id' => $payment->public_id,
            'order_id' => $order->public_id,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_url' => $payment->payment_url,
            'created_at' => $payment->created_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
