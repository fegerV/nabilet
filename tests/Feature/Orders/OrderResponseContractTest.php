<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Форма ответа заказов — ровно схема `Order` контракта.
 *
 * ЗАЧЕМ ЭТОТ ФАЙЛ
 *   До правки (§3.27.4) в проекте жили ТРИ несогласованные формы одного заказа:
 *     1. схема `Order` контракта — `totals` объектом, покупатель тремя плоскими
 *        полями, `items[].event_title`;
 *     2. класс `OrderResource` — `total_amount` плоско, `customer{email,phone,
 *        first_name,last_name}` объектом, `items_count`/`expires_at`/`paid_amount`,
 *        которых в таблице нет вовсе;
 *     3. то, что реально уходило, — КОЛОНКИ таблицы: `index()`, `show()` и
 *        `mine()` сериализовали модель `Order` напрямую.
 *
 *   Наблюдаемые следствия формы №3: админский список заказов показывал «—» в
 *   колонке «Покупатель» (читал `o.customer.email` формы №2), а наружу уходили
 *   внутренние BIGINT (`user_id`, `organization_id`, `promo_code_id`, `cart_id`,
 *   `session_id`, `event_id`) вопреки `DATABASE.md` §9.
 *
 *   Здесь форма запирается на всех четырёх путях, чтобы разойтись снова они не
 *   могли молча: `GET /orders`, `GET /orders/{order}`, `GET /me/orders` и
 *   `POST /orders` (обе формы создания).
 */
class OrderResponseContractTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;

    /** Внутренние идентификаторы: за пределами схемы `Order` их быть не должно. */
    private const INTERNAL_KEYS = [
        'user_id', 'organization_id', 'promo_code_id', 'cart_id',
        'session_id', 'event_id',
    ];

    private function registerAndLogin(string $email = 'buyer@example.com'): string
    {
        $password = 'Kv7#mRta2!Qz';

        $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertStatus(200)->json('data.token');
    }

    /**
     * Покупка настоящим путём: корзина → checkout.
     *
     * HTTP, а не сид напрямую: проверяемая форма — это то, что уходит клиенту,
     * и её должен собирать тот же код, которым пользуется витрина.
     *
     * @return array{0: int, 1: string} внутренний id и public_id заказа
     */
    private function buyOneSeat(string $token): array
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::PRICE,
        );

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], [CartToken::HEADER => 'buyer-cart-token'])->assertStatus(201);

        $response = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_email_confirmation' => 'buyer@example.com',
            'customer_name' => 'Иван Петров',
        ], [CartToken::HEADER => 'buyer-cart-token'])->assertStatus(200);

        $publicId = (string) $response->json('data.order_id');

        return [(int) DB::table('orders')->where('public_id', $publicId)->value('id'), $publicId];
    }

    private function assertContractShape(array $order, string $context): void
    {
        foreach (['id', 'order_number', 'status', 'totals', 'customer_email', 'created_at', 'items'] as $key) {
            $this->assertArrayHasKey($key, $order, "{$context}: в заказе нет обязательного `{$key}`");
        }

        foreach (self::INTERNAL_KEYS as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $order,
                "{$context}: наружу уходит внутренний идентификатор `{$key}` (DATABASE.md §9)",
            );
        }

        // `id` — это `public_id`, строка фиксированной длины, а не BIGINT.
        $this->assertIsString($order['id'], "{$context}: `id` должен быть строкой");
        $this->assertSame(26, mb_strlen((string) $order['id']), "{$context}: `id` — не public_id");

        foreach (['subtotal_amount', 'discount_amount', 'fee_amount', 'total_amount', 'currency'] as $key) {
            $this->assertArrayHasKey($key, $order['totals'], "{$context}: в `totals` нет `{$key}`");
        }

        unset($order['items'], $order['totals']);

        // Ничего кроме полей схемы `Order` быть не должно: форма не «примерно
        // такая», а ровно такая.
        $this->assertSame(
            [
                'id', 'order_number', 'status', 'payment_status', 'customer_email',
                'customer_name', 'customer_phone', 'created_at', 'paid_at',
            ],
            array_keys($order),
            "{$context}: набор полей заказа разошёлся с контрактом",
        );
    }

    public function test_get_orders_returns_a_flat_list_with_meta(): void
    {
        $token = $this->registerAndLogin();
        [$orderId, $publicId] = $this->buyOneSeat($token);

        $response = $this->withToken($token)->getJson('/api/v1/orders')->assertStatus(200);

        // `data` — СПИСОК, а не объект пагинатора: раньше здесь уходил сам
        // пагинатор, и список оказывался на `data.data[]` — на два уровня
        // глубже, чем обещает `OrderListResponse`.
        $data = (array) $response->json('data');

        $this->assertIsList($data, 'data должен быть списком');
        $this->assertCount(1, $data);
        $this->assertContractShape($data[0], 'GET /orders');

        $this->assertSame($response->json('meta.current_page'), 1);
        $this->assertSame($response->json('meta.total'), 1);
    }

    public function test_get_one_order_returns_the_contract_shape(): void
    {
        $token = $this->registerAndLogin();
        [$orderId, $publicId] = $this->buyOneSeat($token);

        $this->withToken($token)
            ->getJson("/api/v1/orders/{$orderId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $publicId)
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.event_title', 'Тестовое событие');

        $order = (array) $this->withToken($token)->getJson("/api/v1/orders/{$orderId}")->json('data');
        $this->assertContractShape($order, 'GET /orders/{order}');
    }

    public function test_my_orders_returns_the_contract_shape(): void
    {
        $token = $this->registerAndLogin();
        [$orderId, $publicId] = $this->buyOneSeat($token);

        $data = (array) $this->withToken($token)
            ->getJson('/api/v1/me/orders')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertContractShape($data[0], 'GET /me/orders');
    }

    public function test_create_order_answers_the_order_schema_not_the_checkout_summary(): void
    {
        $token = $this->registerAndLogin();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::PRICE,
        );

        // Корзина живёт по токену: он же подтверждает право её оформлять.
        $cartId = (string) $this->withHeaders([CartToken::HEADER => 'contract-cart-token'])
            ->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders([CartToken::HEADER => 'contract-cart-token'])
            ->postJson("/api/v1/carts/{$cartId}/items", [
                'inventory_item_id' => $inventoryItemId,
                'quantity' => 1,
            ])->assertStatus(201);

        $order = (array) $this->withToken($token)
            ->postJson('/api/v1/orders', [
                'cart_id' => $cartId,
                'customer_email' => 'buyer@example.com',
                'customer_name' => 'Иван Петров',
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertContractShape($order, 'POST /orders (cart_id)');
    }

    public function test_create_order_direct_form_answers_the_order_schema(): void
    {
        $token = $this->registerAndLogin();

        [$organizationId, , , $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::PRICE,
        );

        $order = (array) $this->withToken($token)->postJson('/api/v1/orders', [
            'organization_id' => $organizationId,
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Иван Петров',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.totals.total_amount', self::PRICE)
            ->json('data');

        $this->assertContractShape($order, 'POST /orders (прямая форма)');
    }
}
