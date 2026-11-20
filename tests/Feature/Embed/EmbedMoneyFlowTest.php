<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\EmbedFixtures;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Happy-path денежного контура embed API.
 *
 * Раньше тесты Modules\Embed проверяли только отказы gate — успешный путь
 * carts → items → orders → payment не был покрыт вообще. Для frontend'а
 * это означает, что переключение на embed-ручки шло вслепую.
 */
final class EmbedMoneyFlowTest extends TestCase
{
    use EmbedFixtures;
    use RefreshDatabase;
    use SellableSeatFixtures;

    public function test_seatmap_is_compatible_with_the_storefront_inventory_contract(): void
    {
        [$organizationId, , $sessionId] = $this->seedSellableSeat(available: 3);
        $token = $this->seedEmbedAccess($organizationId);

        $payload = $this->getJson($this->embedUrl("/api/v1/embed/sessions/{$sessionId}/seatmap", $token), $this->embedHeaders())
            ->assertOk()
            ->json();

        $this->assertIsArray($payload['data']['data'] ?? null, 'embed-карта должна отдавать конверт витринного /inventory');
        $this->assertSame(count($payload['inventory']), $payload['meta']['total']);
        $this->assertArrayHasKey('max_tickets_per_order', $payload['meta']);
        $this->assertContains($payload['meta']['stage_side'], ['top', 'bottom']);
        $this->assertIsArray($payload['schema'] ?? null);
    }

    public function test_a_seat_can_be_held_by_the_public_id_from_the_seatmap(): void
    {
        [$organizationId, , $sessionId, $inventoryId] = $this->seedSellableSeat(available: 3);
        $publicId = (string) DB::table('inventory_items')->where('id', $inventoryId)->value('public_id');
        $token = $this->seedEmbedAccess($organizationId);

        $cart = $this->postJson($this->embedUrl('/api/v1/embed/carts', $token), [
            'session_id' => (string) $sessionId,
        ], $this->embedHeaders())->assertCreated()->json();

        $cartId = (string) $cart['data']['id'];
        $checkoutToken = (string) $cart['checkout_token'];
        $this->assertNotSame('', $checkoutToken);

        $this->postJson(
            $this->embedUrl("/api/v1/embed/carts/{$cartId}/items", $token),
            ['inventory_item_id' => $publicId, 'quantity' => 1],
            $this->embedHeaders() + [CartToken::HEADER => $checkoutToken],
        )->assertCreated();

        $numericCartId = (int) DB::table('carts')->where('public_id', $cartId)->value('id');
        $this->assertSame(1, (int) DB::table('cart_items')->where('cart_id', $numericCartId)->count());
    }

    public function test_an_unknown_seat_reference_is_a_404_not_a_silent_zero(): void
    {
        [$organizationId, , $sessionId] = $this->seedSellableSeat(available: 1);
        $token = $this->seedEmbedAccess($organizationId);
        $cart = $this->postJson($this->embedUrl('/api/v1/embed/carts', $token), [
            'session_id' => (string) $sessionId,
        ], $this->embedHeaders())->assertCreated()->json();
        $cartId = (string) $cart['data']['id'];

        $this->postJson(
            $this->embedUrl("/api/v1/embed/carts/{$cartId}/items", $token),
            ['inventory_item_id' => 'not-a-real-seat', 'quantity' => 1],
            $this->embedHeaders() + [CartToken::HEADER => (string) $cart['checkout_token']],
        )->assertNotFound();
    }
}
