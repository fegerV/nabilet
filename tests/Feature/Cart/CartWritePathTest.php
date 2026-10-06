<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Путь записи корзины против реальной схемы.
 *
 * Зачем этот файл появился. `app/Modules/Cart/Services/CartService.php` (627 строк,
 * удержание мест, списание и возврат инвентаря, оформление заказа) не был покрыт
 * НИ ОДНИМ тестом: ни один Feature-тест не обращался к `/api/v1/cart/*`, и ни один
 * тест в проекте не упоминал сам класс. `tests/Unit/CartTest.php` с его 38 тестами
 * проверяет доменный кластер `Cart\Domain\Superseded\*`, который в боевом пути не
 * участвует вовсе (см. P1.9.9 в docs/CODE-QUALITY-GUIDE.md) — то есть создавал
 * видимость покрытия там, где его не было.
 *
 * Тесты идут через HTTP, а не через сервис: так они проверяют контракт, который
 * видит клиент, и переживают разделение `CartService` на несколько классов (P2),
 * не требуя правки при каждом переносе метода.
 *
 * Проверяются именно инварианты инвентаря, потому что цена ошибки здесь —
 * не 500-я, а тихая утечка мест: место списано из `available_quantity`, но ни
 * один путь возврата его больше не видит, и оно исчезает из продажи навсегда.
 */
class CartWritePathTest extends TestCase
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

    private function available(int $inventoryItemId): int
    {
        return (int) DB::table('inventory_items')
            ->where('id', $inventoryItemId)
            ->value('available_quantity');
    }

    private function addItem(int $sessionId, int $inventoryItemId, int $quantity = 1)
    {
        return $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => $quantity,
        ], $this->authHeaders());
    }

    // ── addItem ──────────────────────────────────────────────────────────────

    public function test_add_item_reserves_inventory_and_materializes_a_hold(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 2)->assertStatus(201);

        $this->assertSame(
            self::CAPACITY - 2,
            $this->available($inventoryItemId),
            'addItem не списал места из available_quantity.'
        );

        $cart = DB::table('carts')->where('session_id', $sessionId)->where('cart_token', self::TOKEN)->first();
        $this->assertNotNull($cart, 'Корзина покупателя не создана.');
        $this->assertSame('active', $cart->status);
        $this->assertNotNull($cart->expires_at, 'carts.expires_at обязателен: от него живёт таймер витрины.');
        $this->assertSame(2 * self::PRICE, (int) $cart->total_amount);

        $item = DB::table('cart_items')->where('cart_id', $cart->id)->first();
        $this->assertNotNull($item, 'Позиция корзины не записана.');
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(self::PRICE, (int) $item->unit_price);
        $this->assertSame(2 * self::PRICE, (int) $item->total_price);

        // A13: холд обязан быть материализован, иначе защита от «оплаты после
        // истечения» (PaymentService::validateHoldsForOrder) не находит ничего.
        $hold = DB::table('seat_holds')->where('cart_id', $cart->id)->first();
        $this->assertNotNull($hold, 'Холд seat_holds не создан — A13-защита мертва.');
        $this->assertSame(2, (int) $hold->quantity);
        $this->assertNull($hold->released_at);
        $this->assertNull($hold->converted_at);
        $this->assertSame(
            $cart->expires_at,
            $hold->expires_at,
            'Холд живёт дольше корзины или меньше неё — таймеры разъедутся.'
        );
    }

    /**
     * Повторное добавление того же места (двойной клик, retry, вторая вкладка)
     * увеличивает quantity позиции. Инвентарь при этом списывается ещё раз —
     * значит, и холд обязан покрывать новое количество.
     *
     * Если холд отстаёт от позиции, место утекает: `releaseCartInventory()`
     * возвращает ровно `seat_holds.quantity`, а не `cart_items.quantity`.
     */
    public function test_repeat_add_keeps_the_hold_in_step_with_the_cart_item(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);
        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);

        $cart = DB::table('carts')->where('session_id', $sessionId)->where('cart_token', self::TOKEN)->first();

        $itemQuantity = (int) DB::table('cart_items')
            ->where('cart_id', $cart->id)
            ->where('inventory_item_id', $inventoryItemId)
            ->value('quantity');

        $this->assertSame(2, $itemQuantity, 'Повторное добавление не накопило quantity позиции.');
        $this->assertSame(
            self::CAPACITY - 2,
            $this->available($inventoryItemId),
            'Инвентарь списан не на 2 места.'
        );

        $heldQuantity = (int) DB::table('seat_holds')
            ->where('cart_id', $cart->id)
            ->where('inventory_item_id', $inventoryItemId)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->sum('quantity');

        $this->assertSame(
            $itemQuantity,
            $heldQuantity,
            'Холд покрывает меньше мест, чем позиция корзины: место, списанное '
            . 'вторым добавлением, не вернёт ни один путь освобождения.'
        );
    }

    /**
     * Конечное следствие расхождения «позиция 2 места / холд 1 место»: когда
     * корзина истекает, инвентарь возвращает `releaseCartInventory()`, а она
     * идёт строго по `seat_holds`. Одно место не возвращается никем и навсегда
     * исчезает из продажи.
     *
     * Путь воспроизведения — тот же, что у покупателя: положил место дважды
     * (двойной клик), не оплатил, корзина истекла.
     */
    public function test_expired_cart_returns_every_seat_it_reserved(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);
        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);

        $this->assertSame(self::CAPACITY - 2, $this->available($inventoryItemId));

        // Уходим за `carts.expires_at` (15 минут по умолчанию).
        $this->travel(20)->minutes();

        $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CART_EXPIRED');

        $this->assertSame(
            self::CAPACITY,
            $this->available($inventoryItemId),
            'Истёкшая корзина вернула в продажу не все удержанные места — '
            . 'часть инвентаря утекла безвозвратно.'
        );
    }

    public function test_add_item_rejects_a_seat_from_another_session(): void
    {
        [$sessionA] = $this->seedSellable();
        [, , , $foreignItem] = $this->seedSellableSeat(available: self::CAPACITY);

        $this->addItem($sessionA, $foreignItem, 1)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_SESSION_MISMATCH');

        $this->assertSame(
            self::CAPACITY,
            $this->available($foreignItem),
            'Чужеродное место всё равно было списано из инвентаря.'
        );
    }

    // ── removeItem ───────────────────────────────────────────────────────────

    public function test_remove_item_restores_the_inventory_it_took(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 2)->assertStatus(201);
        $this->assertSame(self::CAPACITY - 2, $this->available($inventoryItemId));

        $cart = DB::table('carts')->where('session_id', $sessionId)->where('cart_token', self::TOKEN)->first();
        $itemId = (int) DB::table('cart_items')->where('cart_id', $cart->id)->value('id');

        $this->deleteJson('/api/v1/cart/items/' . $itemId, [
            'session_id' => $sessionId,
        ], $this->authHeaders())->assertStatus(200);

        $this->assertSame(
            self::CAPACITY,
            $this->available($inventoryItemId),
            'removeItem вернул не все места: available_quantity не восстановлен.'
        );

        $this->assertSame(
            0,
            (int) DB::table('seat_holds')
                ->where('cart_id', $cart->id)
                ->whereNull('released_at')
                ->whereNull('converted_at')
                ->count(),
            'После удаления позиции остался активный холд — sweeper вернёт место повторно.'
        );
    }

    // ── extendHold ───────────────────────────────────────────────────────────

    public function test_extend_hold_moves_both_the_cart_and_the_hold_expiry(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);

        $cart = DB::table('carts')->where('session_id', $sessionId)->where('cart_token', self::TOKEN)->first();

        // Сдвигаем время: `carts.expires_at` хранится с точностью до секунды
        // (Laravel пишет Eloquent-даты как `Y-m-d H:i:s`), поэтому два вызова
        // внутри одной секунды дали бы одинаковую отметку и тест не отличил бы
        // реальное продление от его отсутствия.
        $this->travel(5)->minutes();

        $this->postJson('/api/v1/cart/extend', [
            'session_id' => $sessionId,
        ], $this->authHeaders())->assertStatus(200);

        $extended = DB::table('carts')->where('id', $cart->id)->first();

        $this->assertTrue(
            $extended->expires_at > $cart->expires_at,
            sprintf(
                'Продление не отодвинуло carts.expires_at. Было: %s, стало: %s',
                (string) $cart->expires_at,
                (string) $extended->expires_at,
            )
        );

        $holdExpiry = DB::table('seat_holds')->where('cart_id', $cart->id)->value('expires_at');

        $this->assertSame(
            $extended->expires_at,
            $holdExpiry,
            'Корзина продлена, а холд — нет: sweeper снимет место раньше таймера витрины.'
        );
    }

    // ── checkout ─────────────────────────────────────────────────────────────

    public function test_checkout_converts_the_cart_into_an_order_with_items(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 2)->assertStatus(201);

        $response = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Покупатель',
        ], $this->authHeaders())->assertStatus(200);

        $orderId = (int) DB::table('orders')
            ->where('public_id', $response->json('data.order_id'))
            ->value('id');

        $this->assertGreaterThan(0, $orderId, 'Заказ не создан.');

        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame('pending', $order->status);
        $this->assertSame(2 * self::PRICE, (int) $order->total_amount);
        $this->assertSame('buyer@example.com', $order->customer_email);

        // A6-цепочка «заказ → оплата → билет»: без cart_id/session_id билеты не
        // выпускаются, а PaymentService не находит холды для валидации.
        $this->assertNotNull($order->cart_id, 'orders.cart_id не заполнен — холды не найдутся.');
        $this->assertSame($sessionId, (int) $order->session_id);
        $this->assertNotNull($order->event_id);

        $item = DB::table('order_items')->where('order_id', $orderId)->first();
        $this->assertNotNull($item, 'Позиции заказа не созданы.');
        $this->assertSame($inventoryItemId, (int) $item->inventory_item_id);
        $this->assertSame(2, (int) $item->quantity);

        $this->assertSame(
            'converted',
            DB::table('carts')->where('id', $order->cart_id)->value('status'),
            'Корзина не помечена converted — повторный checkout возможен.'
        );

        // A6: до подтверждения оплаты место НЕ sold, иначе оно «сгорает» при неоплате.
        $this->assertNotSame(
            'sold',
            DB::table('inventory_items')->where('id', $inventoryItemId)->value('status'),
            'Место помечено sold на checkout, до оплаты — дефект «места сгорают при неоплате».'
        );
    }

    public function test_checkout_on_an_empty_cart_is_rejected(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        // Корзины ещё нет вовсе → 404: оформлять нечего, и создавать пустой заказ
        // «на будущее» сервис не должен.
        $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
        ], $this->authHeaders())->assertStatus(404);

        // Корзина есть, но позиции из неё убрали → 422 CART_EMPTY, а не заказ на 0.
        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);

        $cart = DB::table('carts')->where('session_id', $sessionId)->where('cart_token', self::TOKEN)->first();
        $itemId = (int) DB::table('cart_items')->where('cart_id', $cart->id)->value('id');

        $this->deleteJson('/api/v1/cart/items/' . $itemId, [
            'session_id' => $sessionId,
        ], $this->authHeaders())->assertStatus(200);

        $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CART_EMPTY');

        $this->assertSame(0, (int) DB::table('orders')->count(), 'Пустая корзина всё же создала заказ.');
    }
}
