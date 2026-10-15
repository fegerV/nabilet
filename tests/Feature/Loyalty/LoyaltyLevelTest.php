<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «Клуб зрителей», фаза 3: `/api/v1/me/loyalty-level`.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. ДОСТУП. Путь под `auth:api`: гостю нечего показать, и он получает 401.
 *   2. ПРОИЗВОДНОЕ ОТ ЗАКАЗОВ. Уровень — функция числа ОПЛАЧЕННЫХ заказов, а не
 *      «факт», записанный при регистрации. Тесты поднимают покупателя до «друга»
 *      (3 оплаченных) и до «VIP» (10) и проверяют и ответ API, и материализованный
 *      столбец `users.loyalty_level_id`, который ведёт обсервер `OrderLoyaltyObserver`.
 *   3. ПОНИЖЕНИЕ. Возврат/отмена оплаченного заказа опускает уровень: API считает
 *      его заново от живого COUNT, а обсервер синхронизирует столбец. Тест
 *      `a_refund_lowers_the_level` меняет статус заказа через модель — именно так
 *      срабатывает Eloquent-событие `updated`, на которое подписан обсервер.
 *   4. ПРОГРЕСС. `next_level` — вложенная схема `LoyaltyLevel` (или null на
 *      вершине), `orders_to_next` — сколько оплаченных заказов до следующего.
 */
class LoyaltyLevelTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private function seedUser(string $email = 'buyer@example.com'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Токен выдаётся напрямую, а не через `/auth/*`: тестам нужен контроль над
     * `user_id` (заказ оформляется на того же покупателя), а форма входа
     * проверяется своими тестами и здесь была бы лишним звеном.
     */
    private function tokenFor(int $userId): string
    {
        return $this->app->make(SessionIssuer::class)->issue($userId);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Оплаченный заказ цепочкой `pending → awaiting_payment → paid`, как в проде.
     * Каждый переход статуса будит `OrderLoyaltyObserver`, который пересчитывает
     * уровень; поэтому уже на третьем заказе столбец `loyalty_level_id` должен
     * показывать «друга».
     */
    private function paidOrderFor(?int $userId): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);

        return $service->markPaid($order->fresh());
    }

    // ── 1. доступ ──

    public function test_the_endpoint_requires_an_account(): void
    {
        $this->getJson('/api/v1/me/loyalty-level')->assertStatus(401);
    }

    // ── 2. производное от заказов ──

    public function test_a_new_buyer_is_at_the_start_level(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        $data = $this->getJson('/api/v1/me/loyalty-level', $this->headers($token))
            ->assertOk()
            ->json('data');

        $this->assertSame('start', $data['slug']);
        $this->assertSame('Старт', $data['name']);
        $this->assertSame(0, $data['paid_orders']);

        // Есть куда расти: следующий уровень — «друг» с порогом 3.
        $this->assertNotNull($data['next_level']);
        $this->assertSame('friend', $data['next_level']['slug']);
        $this->assertSame(3, $data['next_level']['min_paid_orders']);
        $this->assertSame(3, $data['orders_to_next']);

        // Материализованный столбец ещё NULL: уровень считается на лету, а не
        // пишется при регистрации.
        $this->assertNull(DB::table('users')->where('id', $userId)->value('loyalty_level_id'));
    }

    public function test_three_paid_orders_promote_to_friend_and_materialize(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        $this->paidOrderFor($userId);
        $this->paidOrderFor($userId);

        // Два оплаченных — ещё «старт».
        $mid = $this->getJson('/api/v1/me/loyalty-level', $this->headers($token))
            ->assertOk()
            ->json('data');
        $this->assertSame('start', $mid['slug']);
        $this->assertSame(2, $mid['paid_orders']);

        $this->paidOrderFor($userId);

        $data = $this->getJson('/api/v1/me/loyalty-level', $this->headers($token))
            ->assertOk()
            ->json('data');

        $this->assertSame('friend', $data['slug']);
        $this->assertSame(3, $data['paid_orders']);
        $this->assertNotNull($data['next_level']);
        $this->assertSame('vip', $data['next_level']['slug']);
        $this->assertSame(10, $data['next_level']['min_paid_orders']);
        $this->assertSame(7, $data['orders_to_next']);

        // Обсервер записал уровень в столбец: читаем напрямую из базы, без API.
        $this->assertSame(
            DB::table('loyalty_levels')->where('slug', 'friend')->value('id'),
            DB::table('users')->where('id', $userId)->value('loyalty_level_id')
        );
    }

    public function test_ten_paid_orders_promote_to_vip_with_no_next_level(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        for ($i = 0; $i < 10; $i++) {
            $this->paidOrderFor($userId);
        }

        $data = $this->getJson('/api/v1/me/loyalty-level', $this->headers($token))
            ->assertOk()
            ->json('data');

        $this->assertSame('vip', $data['slug']);
        $this->assertSame(10, $data['paid_orders']);

        // На вершине следующего уровня нет — прогресс-бар не показывается.
        $this->assertNull($data['next_level']);
        $this->assertSame(0, $data['orders_to_next']);

        $this->assertSame(
            DB::table('loyalty_levels')->where('slug', 'vip')->value('id'),
            DB::table('users')->where('id', $userId)->value('loyalty_level_id')
        );
    }

    // ── 3. понижение ──

    public function test_a_refund_lowers_the_level_and_the_materialized_column(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        $first = $this->paidOrderFor($userId);
        $this->paidOrderFor($userId);
        $third = $this->paidOrderFor($userId);

        // Три оплаченных → «друг», и столбец материализован.
        $this->assertSame(
            'friend',
            $this->getJson('/api/v1/me/loyalty-level', $this->headers($token))->json('data.slug')
        );

        // Возврат одного оплаченного заказа: меняем статус через модель, чтобы
        // сработало Eloquent-событие `updated` и обсервер пересчитал уровень.
        // `Order::save()` не ловит невалидные переходы (машина состояний живёт в
        // `OrderService`), поэтому это честный путь, по которому ходят возвраты.
        $refunded = Order::query()->findOrFail($third->id);
        $refunded->status = 'refunded';
        $refunded->save();

        $data = $this->getJson('/api/v1/me/loyalty-level', $this->headers($token))
            ->assertOk()
            ->json('data');

        // Живой COUNT теперь 2 → «старт».
        $this->assertSame('start', $data['slug']);
        $this->assertSame(2, $data['paid_orders']);
        $this->assertSame('friend', $data['next_level']['slug']);
        $this->assertSame(1, $data['orders_to_next']);

        // И материализованный столбец вернулся на «старт».
        $this->assertSame(
            DB::table('loyalty_levels')->where('slug', 'start')->value('id'),
            DB::table('users')->where('id', $userId)->value('loyalty_level_id')
        );

        // Первые два заказа не тронуты: уровень опустился, а не обнулился.
        $this->assertNotNull($first->fresh());
        $this->assertNotNull($third->fresh());
    }
}
