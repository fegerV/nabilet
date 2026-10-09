<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Tests\Support\EmbedFixtures;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * The §18 gate in front of `/api/v1/embed/*`, against the real schema.
 *
 * ТЗ §18 names five checks and each one has a test here, because each one fails
 * silently when it is missing: a widget with no token, a widget on a site nobody
 * listed, a widget reading another organization's event, a widget reading a draft
 * event, and a widget starting a payment it has no right to. None of them produces
 * a 500; they produce a 200 on somebody else's data.
 *
 * THE DISTINCTION THE WHOLE FILE IS ABOUT:
 *   a refused *credential* is 403 with a distinct code — "you never configured
 *   this" must be distinguishable from "this site is not on your list";
 *   a refused *resource* is 404 — answering 403 for "event 7" would confirm that
 *   event 7 exists in some other organization.
 */
class EmbedSecurityTest extends TestCase
{
    use EmbedFixtures;
    use RefreshDatabase;
    use SellableSeatFixtures;

    /** @return array{0: int, 1: int} [organizationId, eventId] */
    private function seedPublishedEvent(): array
    {
        [$organizationId, $eventId] = $this->seedSellableSeat(available: 10);

        return [$organizationId, $eventId];
    }

    // ── 1. The embed token ───────────────────────────────────────────────────

    public function test_a_missing_embed_token_is_refused(): void
    {
        [, $eventId] = $this->seedPublishedEvent();

        $response = $this->getJson("/api/v1/embed/events/{$eventId}", $this->embedHeaders());

        $response->assertStatus(403)->assertJsonPath('error.code', 'EMBED_TOKEN_REQUIRED');
    }

    public function test_an_unknown_embed_token_is_refused(): void
    {
        [, $eventId] = $this->seedPublishedEvent();

        $response = $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", 'embed_this_token_was_never_issued'),
            $this->embedHeaders()
        );

        $response->assertStatus(403)->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    public function test_a_key_without_the_embed_scope_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->seedEmbedDomain($organizationId, 'partner.example');

        // A perfectly valid key that simply is not an embed token.
        $token = $this->seedEmbedKey($organizationId, scopes: ['orders:read']);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    public function test_a_revoked_key_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->seedEmbedDomain($organizationId, 'partner.example');

        $token = $this->seedEmbedKey($organizationId, revokedAt: now()->subMinute()->toDateTimeString());

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    public function test_an_expired_key_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->seedEmbedDomain($organizationId, 'partner.example');

        $token = $this->seedEmbedKey($organizationId, expiresAt: now()->subMinute()->toDateTimeString());

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    public function test_a_key_with_null_scopes_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->seedEmbedDomain($organizationId, 'partner.example');

        // `scopes_json` NULL is two-valued — "nothing" or "everything" — and the
        // schema does not say which. Fail closed on the reading that would be
        // catastrophic if the other one was meant.
        $token = $this->seedEmbedKey($organizationId, scopes: null);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    public function test_a_key_with_an_empty_scope_list_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->seedEmbedDomain($organizationId, 'partner.example');

        $token = $this->seedEmbedKey($organizationId, scopes: []);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID');
    }

    public function test_a_key_belonging_to_no_organization_is_refused(): void
    {
        [, $eventId] = $this->seedPublishedEvent();

        // The column is NULLABLE, so this row is insertable. `ApiKeyPolicy`'s read
        // path does not look at the tenant at all — only `issueDecision()` and
        // `auditDecision()` do — so without an explicit check this key would be
        // accepted and would resolve to "organization 0".
        $token = $this->seedEmbedKey(null);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_INVALID')
            ->assertJsonPath('error.details.reason', 'no_organization');
    }

    // ── 2. The Origin ────────────────────────────────────────────────────────

    public function test_a_missing_origin_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_ORIGIN_MISSING');
    }

    public function test_an_organization_without_a_whitelist_cannot_be_embedded_at_all(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();

        // No `embed_domains` row. The alternative reading — "no list means no
        // restriction" — turns forgetting to configure the feature into publishing
        // it to the whole internet.
        $token = $this->seedEmbedKey($organizationId);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_DOMAINS_NOT_CONFIGURED');
    }

    public function test_an_inactive_domain_does_not_grant_access(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $this->seedEmbedDomain($organizationId, 'partner.example', active: false);
        $token = $this->seedEmbedKey($organizationId);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_DOMAINS_NOT_CONFIGURED');
    }

    public function test_an_unlisted_origin_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders('https://attacker.example')
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_ORIGIN_NOT_ALLOWED')
            ->assertJsonPath('error.details.origin', 'attacker.example');
    }

    public function test_a_bare_whitelist_entry_does_not_admit_its_subdomain(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId, 'partner.example');

        // `partner.example` is listed exactly; `widget.partner.example` is a
        // different origin and was never listed. Widening the bare entry is how one
        // listed site becomes an open redirect target.
        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders('https://widget.partner.example')
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_ORIGIN_NOT_ALLOWED');
    }

    public function test_a_dot_prefixed_whitelist_entry_admits_its_subdomains(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId, '.partner.example');

        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders('https://widget.partner.example')
        )->assertStatus(200);
    }

    public function test_the_origin_is_matched_case_insensitively_and_ignores_the_port(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders('https://PARTNER.example:8443')
        )->assertStatus(200);
    }

    public function test_a_null_origin_is_refused(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        // A sandboxed iframe sends `Origin: null`; there is no site to match.
        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$eventId}", $token),
            $this->embedHeaders('null')
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_ORIGIN_MISSING');
    }

    // ── 3–4. Organization ownership and event access ─────────────────────────

    public function test_a_fully_configured_request_returns_the_event(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.id', $eventId)
            ->assertJsonPath('data.status', 'published');
    }

    public function test_another_organizations_event_is_a_404_not_a_403(): void
    {
        [, $foreignEventId] = $this->seedPublishedEvent();

        // A second organization with a fully valid embed configuration.
        [$ownOrganizationId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($ownOrganizationId);

        // 404, not 403: a 403 would confirm that event exists somewhere else.
        $this->getJson(
            $this->embedUrl("/api/v1/embed/events/{$foreignEventId}", $token),
            $this->embedHeaders()
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
    }

    public function test_a_draft_event_is_a_404(): void
    {
        [$organizationId, $eventId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        DB::table('events')->where('id', $eventId)->update(['status' => 'draft']);

        $this->getJson($this->embedUrl("/api/v1/embed/events/{$eventId}", $token), $this->embedHeaders())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
    }

    public function test_a_session_of_a_draft_event_is_a_404(): void
    {
        [$organizationId, $eventId, $sessionId] = $this->seedSellableSeat(available: 10);
        $token = $this->seedEmbedAccess($organizationId);

        DB::table('events')->where('id', $eventId)->update(['status' => 'draft']);

        // §18's "event access" must hold on the seat map too, not only on the event
        // page: otherwise a widget could read the geometry of a draft event.
        $this->getJson(
            $this->embedUrl("/api/v1/embed/sessions/{$sessionId}/seatmap", $token),
            $this->embedHeaders()
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
    }

    public function test_another_organizations_session_is_a_404(): void
    {
        [, , $foreignSessionId] = $this->seedSellableSeat(available: 10);

        [$ownOrganizationId] = $this->seedSellableSeat(available: 10);
        $token = $this->seedEmbedAccess($ownOrganizationId);

        $this->getJson(
            $this->embedUrl("/api/v1/embed/sessions/{$foreignSessionId}/seatmap", $token),
            $this->embedHeaders()
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
    }

    // ── The gate runs before the lookup ──────────────────────────────────────

    public function test_the_gate_refuses_before_the_resource_is_looked_up(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        // A non-existent event, a listed origin, a valid token: the answer is about
        // the *request*, so it must be 403. Answering 404 here would let a caller
        // probe which ids exist without ever passing the gate.
        $this->getJson(
            $this->embedUrl('/api/v1/embed/events/999999', $token),
            $this->embedHeaders('https://attacker.example')
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_ORIGIN_NOT_ALLOWED');
    }

    public function test_a_non_numeric_event_path_is_not_coerced_to_an_id(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        // MySQL compares a BIGINT column against a string by coercing it, so
        // `WHERE id = '1abc'` matches row 1. The route's `whereNumber` is what
        // stops `/embed/events/1abc` from opening event 1.
        $this->getJson(
            $this->embedUrl('/api/v1/embed/events/1abc', $token),
            $this->embedHeaders()
        )->assertStatus(404);
    }

    // ── The gate applies to the write endpoints too ──────────────────────────

    public function test_the_embed_token_is_required_on_the_write_endpoints(): void
    {
        $this->postJson('/api/v1/embed/carts', ['session_id' => '1'], $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_REQUIRED');

        $this->postJson('/api/v1/embed/orders', ['cart_id' => '1', 'customer_email' => 'a@b.c'], $this->embedHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_TOKEN_REQUIRED');
    }

    public function test_the_origin_is_required_on_the_write_endpoints(): void
    {
        [$organizationId] = $this->seedPublishedEvent();
        $token = $this->seedEmbedAccess($organizationId);

        $this->postJson($this->embedUrl('/api/v1/embed/carts', $token), ['session_id' => '1'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_ORIGIN_MISSING');
    }

    public function test_the_checkout_token_is_required_on_cart_items(): void
    {
        [$organizationId, , $sessionId] = $this->seedSellableSeat(available: 10);
        $token = $this->seedEmbedAccess($organizationId);

        // A valid embed token and a listed Origin, but no `X-Checkout-Token`: the
        // caller has not shown that the cart is theirs.
        $this->postJson(
            $this->embedUrl("/api/v1/embed/carts/{$sessionId}/items", $token),
            ['inventory_item_id' => 1, 'quantity' => 1],
            $this->embedHeaders()
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMBED_CHECKOUT_TOKEN_REQUIRED');
    }

    public function test_a_cart_held_by_somebody_else_is_a_404(): void
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 10);
        $embedToken = $this->seedEmbedAccess($organizationId);

        // A cart created by a different buyer's token.
        $this->postJson(
            $this->embedUrl('/api/v1/embed/carts', $embedToken),
            ['session_id' => (string) $sessionId],
            $this->embedHeaders() + ['X-Checkout-Token' => '11111111-2222-3333-4444-555555555555']
        )->assertStatus(201);

        $cart = DB::table('carts')->where('session_id', $sessionId)->first();
        $this->assertNotNull($cart);

        // Same cart id, a different checkout token: 404, exactly like a cart that
        // does not exist. A cart id alone must not let one buyer extend another's
        // reservation.
        $this->postJson(
            $this->embedUrl("/api/v1/embed/carts/{$cart->public_id}/items", $embedToken),
            ['inventory_item_id' => $inventoryItemId, 'quantity' => 1],
            $this->embedHeaders() + ['X-Checkout-Token' => '99999999-8888-7777-6666-555555555555']
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CART_NOT_FOUND');
    }
}
