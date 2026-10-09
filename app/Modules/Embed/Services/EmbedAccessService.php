<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Analytics\Models\EmbedDomain;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Embed\Domain\EmbedOrigin;
use Nabilet\Modules\Embed\Domain\EmbedScope;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Security\Domain\ApiKeyPolicy;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\System\Models\ApiKey;

/**
 * The gate in front of every `/api/v1/embed/*` endpoint (ТЗ §18).
 *
 * §18 names five checks and this class performs all five, in the order that makes
 * each one meaningful:
 *
 *   1. **embed token** — a row in `api_keys` carrying the `embed` scope. The four
 *      rules that decide whether a key may be used at all (revoked, unbounded,
 *      expired, scopeless) are NOT re-implemented here: they live in
 *      `ApiKeyPolicy`, which is pure and already tested. A second copy of them
 *      would be a second answer to "is this credential alive".
 *   2. **Origin** — the site the widget is embedded on, matched against the
 *      organization's `embed_domains` allow-list by `EmbedOrigin`.
 *   3. **organization ownership** — every resource is re-read scoped to the
 *      organization the token names, never trusted from the path.
 *   4. **event access** — only a publicly visible event may be embedded.
 *   5. **payment capability** — delegated to the payment path itself, which
 *      already refuses a non-payable order; see `EmbedController::createPayment`.
 *
 * FAIL-CLOSED, AND EVERY FAILURE IS 403 — NOT 404.
 *   A refused token, a missing Origin, an unconfigured allow-list and a
 *   non-listed site are all 403, with distinct `error.code` values so the
 *   organizer can tell "you never configured this" from "this site is not on your
 *   list". A *resource* that does not exist or belongs to somebody else is 404,
 *   because that is a different question and answering it with 403 would confirm
 *   that the id exists.
 *
 * AN UNCONFIGURED ALLOW-LIST REFUSES EVERYONE.
 *   `docs/ARCHITECTURE.md:324` already decided this for the CSP side —
 *   `frame-ancestors 'none'` when nothing is configured. The API matches it: an
 *   organization with no active `embed_domains` row cannot be embedded anywhere.
 *   The alternative reading ("no list means no restriction") turns forgetting to
 *   configure the feature into publishing it to the whole internet.
 */
final class EmbedAccessService
{
    /** The query parameter the contract names for the embed token. */
    public const TOKEN_QUERY = 'embed_token';

    public function __construct(
        private readonly ApiKeyPolicy $keys,
    ) {}

    /**
     * Authorize an embed request and return the organization it belongs to.
     *
     * @throws AuthError 403 when the token, the Origin or the allow-list refuses
     */
    public function authorize(Request $request): int
    {
        $organizationId = $this->organizationFromToken($request);

        $this->assertOriginAllowed($request, $organizationId);

        return $organizationId;
    }

    /**
     * Resolve the organization from the embed token.
     *
     * The lookup is by digest, never by the presented value: `key_hash` is
     * `CHAR(64)` and UNIQUE, and the plaintext token exists only in the caller's
     * request. A missing token and an unknown token produce the same 403 with the
     * same code, so the endpoint cannot be used to enumerate valid tokens.
     */
    private function organizationFromToken(Request $request): int
    {
        $token = trim((string) $request->query(self::TOKEN_QUERY, ''));

        if ($token === '') {
            throw new AuthError(
                'Embed requests must carry an embed token.',
                'EMBED_TOKEN_REQUIRED',
                403,
            );
        }

        $key = ApiKey::query()
            ->where('key_hash', hash('sha256', $token))
            ->first();

        if ($key === null) {
            throw new AuthError(
                'This embed token is not recognised.',
                'EMBED_TOKEN_INVALID',
                403,
            );
        }

        $decision = $this->keys->scopeDecision(
            $key->toDomain(),
            EmbedScope::EMBED,
            CarbonImmutable::now(),
        );

        if (! $decision->isAllowed()) {
            // The reason names the failing rule (revoked / expired / no scopes /
            // scope not granted) and is safe to return: it describes the token the
            // caller already holds, not anything they could not already see.
            throw new AuthError(
                'This embed token may not be used: ' . (string) $decision->reason,
                'EMBED_TOKEN_INVALID',
                403,
                ['reason' => $decision->verdict],
            );
        }

        // THIS CHECK IS THE ONLY ONE THAT LOOKS AT THE TENANT.
        //   `ApiKeyPolicy` does not: the read path (`scopeDecision()` →
        //   `useDecision()`) answers only "revoked / unbounded / expired /
        //   scopeless", and `belongsToOrganization()` is consulted by
        //   `issueDecision()` and `auditDecision()` alone. So an orgless key with a
        //   valid expiry and the `embed` scope passes the policy — and, because the
        //   column is NULLABLE and `(int) null` is 0, would silently become
        //   "organization 0" and then match nothing, or worse, match a row.
        //   Refusing here is what makes the nullable column fail closed.
        $organizationId = $key->organization_id === null ? 0 : (int) $key->organization_id;

        if ($organizationId === 0) {
            throw new AuthError(
                'This embed token belongs to no organization.',
                'EMBED_TOKEN_INVALID',
                403,
                ['reason' => 'no_organization'],
            );
        }

        return $organizationId;
    }

    /**
     * The Origin must be present and must be on the organization's allow-list.
     *
     * `Referer` is accepted as a fallback because a same-origin navigation (a click
     * inside the widget) sends `Referer` and not always `Origin`; the host is what
     * matters and both headers carry it in the same position.
     */
    private function assertOriginAllowed(Request $request, int $organizationId): void
    {
        $host = EmbedOrigin::hostFrom(
            $request->header('Origin') ?? $request->header('Referer')
        );

        if ($host === null) {
            throw new AuthError(
                'Embed requests must carry an Origin header identifying the embedding site.',
                'EMBED_ORIGIN_MISSING',
                403,
            );
        }

        $domains = EmbedDomain::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->pluck('domain')
            ->all();

        if ($domains === []) {
            throw new AuthError(
                'No embed domains are configured for this organization, so no site may embed it.',
                'EMBED_DOMAINS_NOT_CONFIGURED',
                403,
            );
        }

        if (! EmbedOrigin::isAllowed($host, $domains)) {
            throw new AuthError(
                sprintf('The site "%s" is not allowed to embed this organization.', $host),
                'EMBED_ORIGIN_NOT_ALLOWED',
                403,
                ['origin' => $host],
            );
        }
    }

    /**
     * An event the given organization owns and publishes.
     *
     * A draft, archived or cancelled event is not embeddable, and the answer is
     * 404 rather than 403 for the same reason `EventRepository::findBySlug()` uses
     * one: a caller who is not allowed to see an event must not be able to tell it
     * apart from one that does not exist.
     */
    public function event(string|int $reference, int $organizationId): Event
    {
        $event = $this->findByReference(Event::class, $reference);

        if (! $event instanceof Event || (int) $event->organization_id !== $organizationId) {
            throw new NotFoundError('Event', $reference);
        }

        $this->assertEventIsEmbeddable($event, 'Event', $reference);

        return $event;
    }

    /**
     * A session whose event the given organization owns and publishes.
     *
     * `sessions` carries no `organization_id` — it is reachable only through its
     * event — so ownership is decided by the event, not by the session row. This
     * is the column that does not exist, and inventing one here is exactly how
     * this project has broken relations before.
     *
     * The event's visibility is checked here too, not only in `event()`. §18's
     * "event access" rule would otherwise hold on the event page and not on the
     * seat map or the cart: a widget could read the geometry of, and reserve
     * seats for, a session of a draft event whose page it cannot open.
     */
    public function session(string|int $reference, int $organizationId): Session
    {
        $session = $this->findByReference(Session::class, $reference, ['event']);

        $event = $session instanceof Session ? $session->event : null;

        if (! $session instanceof Session || $event === null || (int) $event->organization_id !== $organizationId) {
            throw new NotFoundError('Session', $reference);
        }

        $this->assertEventIsEmbeddable($event, 'Session', $reference);

        return $session;
    }

    /**
     * A draft, archived or cancelled event is not embeddable.
     *
     * 404 rather than 403, for the same reason `EventRepository::findBySlug()`
     * uses one: a caller who is not allowed to see an event must not be able to
     * tell it apart from one that does not exist.
     *
     * The refusal is reported under the CALLER's resource, not under "Event". A
     * request for a session of a draft event is answered `SESSION_NOT_FOUND`:
     * naming an event in the error, next to a session id, would both read wrong
     * and leak that the event exists but is unpublished.
     */
    private function assertEventIsEmbeddable(Event $event, string $resource, string|int $reference): void
    {
        if (! EventStatus::isPubliclyVisible((string) $event->status)) {
            throw new NotFoundError($resource, $reference);
        }
    }

    /**
     * An order the given organization owns.
     *
     * `$reference` is the external identifier, and the contract types it as a
     * string (`OrderIdPath`) because that is what it is: `OrderResource.id` is
     * `orders.public_id`, a ULID — see the long note in `PaymentController::store()`
     * about the 422 that a `integer` rule on this field once caused. The numeric
     * primary key is still accepted, because the admin and the existing
     * `POST /payments` do send it.
     */
    public function order(string|int $reference, int $organizationId): Order
    {
        $order = $this->findByReference(Order::class, $reference);

        if (! $order instanceof Order || (int) $order->organization_id !== $organizationId) {
            throw new NotFoundError('Order', $reference);
        }

        return $order;
    }

    /**
     * A cart the given organization owns, and that the caller actually holds.
     *
     * Two separate questions, both required. The organization is decided through
     * the cart's session → event, because `carts` has no `organization_id`; the
     * holder is decided by the cart token, because a cart id alone must not let
     * one buyer read or extend another buyer's reservation. A cart that belongs to
     * somebody else is 404, exactly like one that does not exist.
     */
    public function cart(string|int $reference, string $cartToken, int $organizationId): Cart
    {
        $cart = $this->findByReference(Cart::class, $reference);

        if (! $cart instanceof Cart || ! hash_equals((string) $cart->cart_token, $cartToken)) {
            throw new NotFoundError('Cart', $reference);
        }

        // Resolved through the session, which is resolved through the event: this
        // is also what makes a foreign cart a 404 rather than a cross-tenant read.
        $this->session((string) $cart->session_id, $organizationId);

        return $cart;
    }

    /**
     * Look a resource up by its external identifier: the ULID `public_id`, or the
     * numeric primary key.
     *
     * THE NUMERIC BRANCH RUNS ONLY WHEN THE REFERENCE IS ALL DIGITS, and that
     * guard is load-bearing rather than tidy. MySQL compares a BIGINT column
     * against a string by coercing the string — measured on MySQL 8.4.11,
     * `WHERE id = '1abc'` matches row 1 (with warning 1292, not an error) and
     * `WHERE id = 'nope'` matches nothing. Without the guard, `/embed/events/1abc`
     * would open event 1: a different resource than the one requested, answered
     * with 200 instead of 404. `PaymentController::resolveOrder()` carries the same
     * guard for the same reason.
     *
     * `public_id` is always tried first and for every input, so the ULID form keeps
     * working even for a value that happens to be numeric-looking.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $with
     */
    private function findByReference(string $model, string|int $reference, array $with = []): ?Model
    {
        $reference = is_int($reference) ? (string) $reference : trim($reference);

        if ($reference === '') {
            return null;
        }

        return $model::query()
            ->with($with)
            ->where(function ($query) use ($reference): void {
                $query->where('public_id', $reference);

                if (ctype_digit($reference)) {
                    $query->orWhere('id', (int) $reference);
                }
            })
            ->first();
    }
}
