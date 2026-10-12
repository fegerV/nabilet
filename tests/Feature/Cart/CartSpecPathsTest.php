<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Спека-поверхность корзины: `/api/v1/carts/{cart}` вместо `/api/v1/cart`.
 *
 * РАСХОЖДЕНИЕ, КОТОРОЕ ЗАКРЫВАЕТ ЭТОТ ФАЙЛ. Спека (`openapi.yaml`, тег `Cart`)
 * адресует корзину из пути — `POST /api/v1/carts`, `GET /api/v1/carts/{cart}`,
 * `POST /api/v1/carts/{cart}/items`, `DELETE /api/v1/carts/{cart}/items/{item}` —
 * а приложение адресовало её парой (`session_id` в теле + `X-Cart-Token`).
 * Сгенерированный по спеке клиент получал 404 на всех четырёх операциях.
 *
 * Проверяется наблюдаемое: путь, код ответа и форма тела по спеке
 * (`data` + `CartResource`), а не наличие метода в контроллере.
 */
class CartSpecPathsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const CAPACITY = 5;

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return [CartToken::HEADER => self::TOKEN];
    }

    /**
     * @return array{0: int, 1: int} [sessionId, inventoryItemId]
     */
    private function seedSellable(): array
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: self::CAPACITY,
            priceAmount: self::PRICE,
        );

        return [$sessionId, $inventoryItemId];
    }

    public function test_create_returns_the_cart_public_id_for_path_addressing(): void
    {
        [$sessionId] = $this->seedSellable();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201);

        $publicId = $response->json('data.id');

        $this->assertIsString($publicId);
        $this->assertNotSame('', $publicId);

        // Повторный вызов — та же корзина: у покупателя не может быть двух
        // активных корзин на один сеанс (`uq_carts_token_session_status`).
        $again = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201);

        $this->assertSame($publicId, $again->json('data.id'));
    }

    public function test_the_cart_is_addressable_by_public_id_from_the_path(): void
    {
        [$sessionId] = $this->seedSellable();

        $publicId = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/carts/' . $publicId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $publicId)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_foreign_cart_is_404_not_403(): void
    {
        [$sessionId] = $this->seedSellable();

        // Корзина другого покупателя: другой токен.
        $otherPublicId = $this->withHeaders([CartToken::HEADER => '99999999-8888-7777-6666-555555555555'])
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        // Различать «нет такой» и «не твоя» — значит подтверждать существование
        // чужих корзин по идентификатору.
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/carts/' . $otherPublicId)
            ->assertStatus(404);
    }

    public function test_items_are_added_to_the_cart_from_the_path(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $publicId = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts/' . $publicId . '/items', [
                'inventory_item_id' => $inventoryItemId,
                'quantity' => 2,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.id', $publicId);

        // Позиция реально в корзине, и именно в ЭТОЙ.
        $body = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/carts/' . $publicId)
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $body['items']);
        $this->assertSame(2, (int) $body['items'][0]['quantity']);

        // Удаление тоже адресуется из пути.
        $itemId = $body['items'][0]['id'];

        $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/v1/carts/' . $publicId . '/items/' . $itemId)
            ->assertStatus(204);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/carts/' . $publicId)
            ->assertStatus(200)
            ->assertJsonPath('data.items', []);
    }

    public function test_the_session_comes_from_the_cart_not_from_the_body(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $publicId = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        // Тело с чужим `session_id` не должно переносить позицию в другой сеанс:
        // сеанс берётся из корзины, путь уже назвал корзину.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts/' . $publicId . '/items', [
                'inventory_item_id' => $inventoryItemId,
                'quantity' => 1,
                'session_id' => $sessionId + 1000,
            ])
            ->assertStatus(201);
    }
}
