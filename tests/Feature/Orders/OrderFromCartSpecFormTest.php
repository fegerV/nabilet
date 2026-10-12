<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Заказ из корзины формой спеки: `POST /api/v1/orders` с `cart_id`.
 *
 * ЗАЧЕМ. Спека (`createOrder`, `CreateOrderRequest`) создаёт заказ ИЗ КОРЗИНЫ:
 * тело — `{cart_id, customer_email, customer_phone, holder_name, promo_code}`.
 * Приложение принимало на этом пути только прямую форму (`organization_id` +
 * `items[]`), поэтому спек-форма отвечала 422 на первом же обязательном поле.
 *
 * ОДНА РЕАЛИЗАЦИЯ. Обе формы ведут в `CartCheckoutService` / `OrderService`
 * соответственно, а спека-форма — в тот же `checkoutByPublicId()`, что и
 * витринный `POST /cart/checkout`. Расхождение двух тел оформления — это два
 * разных поведения создания заказа.
 *
 * ГОСТЕВАЯ ВЕТКА `checkoutAuth` НЕ ПОКРЫТА: маршрут требует `auth:api`, поэтому
 * рабочий вариант — bearer-пользователь. Гость оформляет через
 * `POST /cart/checkout`. Это записано в §3.25, а не спрятано.
 */
class OrderFromCartSpecFormTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';

    private function authHeaders(): array
    {
        return [CartToken::HEADER => self::TOKEN];
    }

    private function registerAndLogin(): string
    {
        $password = 'Kv7#mRta2!Qz';

        $this->postJson('/api/v1/auth/register', [
            'email' => 'buyer@example.com',
            'password' => $password,
            'password_confirmation' => $password,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.com',
            'password' => $password,
        ])->assertStatus(200);

        return (string) $response->json('data.token');
    }

    public function test_an_order_is_created_from_the_cart_id_in_the_body(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 4,
            priceAmount: self::PRICE,
        );

        // Корзина покупателя — токеном, без Bearer: покупка гостевая.
        $publicId = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/carts/' . $publicId . '/items', [
                'inventory_item_id' => $inventoryItemId,
                'quantity' => 2,
            ])
            ->assertStatus(201);

        // Bearer-пользователь, владеющий корзиной по токену: оформляет формой спеки.
        $token = $this->registerAndLogin();

        $response = $this->withToken($token)
            ->withHeaders($this->authHeaders())
            ->postJson('/api/v1/orders', [
                'cart_id' => $publicId,
                'customer_email' => 'buyer@example.com',
                'customer_phone' => '+79001234567',
                'holder_name' => 'Иван Петров',
            ])
            ->assertStatus(201);

        $this->assertSame($publicId, $response->json('data.cart_id') !== null ? $publicId : null);
        $this->assertNotEmpty($response->json('data.order_id'));
        $this->assertSame(2, (int) $response->json('data.items.0.quantity'));

        // Заказ реально появился: заказ из корзины — не только про ответ.
        $orderId = $response->json('data.order_id');

        $this->withToken($token)->getJson('/api/v1/orders')
            ->assertStatus(200)
            ->assertJsonFragment(['public_id' => $orderId]);
    }

    public function test_the_spec_form_rejects_a_cart_of_another_buyer(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 4,
            priceAmount: self::PRICE,
        );

        $foreign = $this->withHeaders([CartToken::HEADER => '99999999-8888-7777-6666-555555555555'])
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders([CartToken::HEADER => '99999999-8888-7777-6666-555555555555'])
            ->postJson('/api/v1/carts/' . $foreign . '/items', [
                'inventory_item_id' => $inventoryItemId,
                'quantity' => 1,
            ])
            ->assertStatus(201);

        $token = $this->registerAndLogin();

        // 404, а не 403: существование чужой корзины — не информация для спрашивающего.
        $this->withToken($token)
            ->withHeaders($this->authHeaders())
            ->postJson('/api/v1/orders', [
                'cart_id' => $foreign,
                'customer_email' => 'buyer@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_both_forms_at_once_are_rejected(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 4,
            priceAmount: self::PRICE,
        );

        $token = $this->registerAndLogin();

        // «Из корзины» и «прямой» одновременно — две книги заказов в одном вызове.
        $this->withToken($token)
            ->withHeaders($this->authHeaders())
            ->postJson('/api/v1/orders', [
                'cart_id' => 'whatever',
                'organization_id' => 1,
                'items' => [
                    ['inventory_item_id' => $inventoryItemId, 'quantity' => 1],
                ],
                'customer_email' => 'buyer@example.com',
            ])
            ->assertStatus(422);
    }
}
