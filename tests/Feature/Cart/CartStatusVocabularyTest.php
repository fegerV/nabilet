<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Cart\Domain\CartStatus;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Словарь статусов корзины: один владелец вместо шестнадцати копий.
 *
 * Повод. `carts.status` читался и писался голой строкой в 21 месте боевого
 * контура — `'active'` одиннадцать раз, плюс `'converted'` и `'abandoned'`.
 * Владелец словаря существовал, но не здесь:
 * `Nabilet\Modules\Orders\Domain\Superseded\CartState` объявляет себя
 * «единственным домом» этой колонки, содержит ЧЕТЫРЕ значения и лежит в
 * архивном кластере `Superseded/`, который боевой код не видит
 * (`docs/CODE-QUALITY-GUIDE.md` §9.9). Его docblock к тому же утверждал, что
 * CHECK-ограничения на колонке нет — оно есть, и допускает ТРИ значения.
 *
 * ЛОВУШКА, КОТОРУЮ ЗДЕСЬ ЗАКРЫВАЮТ. У архивной модели есть `expired` — состояния,
 * которого `ck_carts_status` не допускает. Класс выглядит авторитетом
 * («единственный дом словаря»), поэтому написать `CartState::EXPIRED` в колонку
 * — естественное движение, которое даёт отказ MySQL, то есть 500 вместо
 * осмысленной ошибки. Тест пришпиливает словарь к схеме: четвёртое значение
 * не пройдёт молча.
 *
 * Поведение, которое защищают живые тесты: только `active` разрешает запись и
 * оформление. Это защита от самого дорогого бага в этом потоке — повторная
 * оплата создаёт ВТОРОЙ заказ из той же корзины и продаёт те же места дважды.
 */
final class CartStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const CAPACITY = 5;

    // ── 1. Словарь пришпилен к схеме ────────────────────────────────────────

    public function test_the_vocabulary_is_exactly_what_the_check_constraint_allows(): void
    {
        $this->assertSame(
            ['active', 'converted', 'abandoned'],
            CartStatus::all(),
            'Словарь разошёлся с ck_carts_status. Колонка допускает ровно эти три значения;'
            . ' четвёртое даст отказ MySQL (500), а не доменную ошибку.'
        );
    }

    /**
     * `expired` из архивного `CartState` — не статус корзины.
     *
     * Проверка существует, потому что класс-авторитет его объявляет: любой,
     * кто «согласует» словари, добавит значение сюда и получит падение на
     * первом же обращении к БД.
     */
    public function test_expired_is_not_a_cart_status(): void
    {
        $this->assertFalse(CartStatus::isValid('expired'));
        $this->assertFalse(CartStatus::acceptsChanges('expired'));
        $this->assertFalse(CartStatus::canCheckOut('expired'));
    }

    /** Незнакомый статус закрывает доступ, а не открывает. */
    public function test_an_unknown_status_fails_closed(): void
    {
        $this->assertFalse(CartStatus::isValid('banana'));
        $this->assertFalse(CartStatus::acceptsChanges('banana'));
        $this->assertFalse(CartStatus::canCheckOut('banana'));
        $this->assertTrue(CartStatus::isValid(CartStatus::ACTIVE));
        $this->assertTrue(CartStatus::acceptsChanges(CartStatus::ACTIVE));
    }

    // ── 2. Живой контур: converted не оформляется второй раз ────────────────

    public function test_a_converted_cart_cannot_be_checked_out_twice(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 2)->assertStatus(201);

        $first = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Покупатель',
        ], $this->authHeaders())->assertStatus(200);

        $firstOrderId = (string) $first->json('data.order_id');

        // Повторное оформление тем же токеном. Корзина теперь `converted`, и
        // guard по статусу обязан вернуть ТОТ ЖЕ заказ идемпотентным replay'м
        // (D-8/S8), а не создать второй: мест хватит только на один.
        $replay = $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Покупатель',
        ], $this->authHeaders())->assertStatus(200);

        $this->assertSame($firstOrderId, (string) $replay->json('data.order_id'));

        $this->assertSame(
            1,
            (int) DB::table('orders')->where('session_id', $sessionId)->count(),
            'Из одной корзины создан второй заказ — места проданы дважды.'
        );

        $this->assertSame(
            CartStatus::CONVERTED,
            (string) DB::table('carts')->where('session_id', $sessionId)->value('status')
        );
    }

    // ── 3. Живой контур: abandoned не воскресает ────────────────────────────

    /**
     * Брошенная корзина не воскресает — но и не теряется.
     *
     * `getOrCreateCart()` ищет корзину именно в статусе `active`, поэтому для
     * брошенной lookup не находит ничего и создаёт НОВУЮ корзину против текущих
     * цен. Воскрешение было бы хуже: в брошенной лежат цены момента ухода, и
     * покупатель оплатил бы по ним после изменения прайса.
     */
    public function test_an_abandoned_cart_is_not_resurrected(): void
    {
        [$sessionId, $inventoryItemId] = $this->seedSellable();

        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);

        $abandonedCartId = (int) DB::table('carts')->where('session_id', $sessionId)->value('id');
        DB::table('carts')->where('id', $abandonedCartId)->update(['status' => CartStatus::ABANDONED]);

        // Новая позиция уходит в НОВУЮ корзину, а не в брошенную.
        $this->addItem($sessionId, $inventoryItemId, 1)->assertStatus(201);

        $carts = DB::table('carts')->where('session_id', $sessionId)->get();

        $this->assertCount(2, $carts, 'Брошенная корзина была переиспользована вместо создания новой.');

        $abandoned = $carts->firstWhere('id', $abandonedCartId);
        $this->assertSame(CartStatus::ABANDONED, (string) $abandoned->status);
        $this->assertSame(
            1,
            (int) DB::table('cart_items')->where('cart_id', $abandonedCartId)->count(),
            'В брошенную корзину добавилась позиция.'
        );

        $fresh = $carts->firstWhere('id', '!=', $abandonedCartId);
        $this->assertSame(CartStatus::ACTIVE, (string) $fresh->status);
        $this->assertSame(
            1,
            (int) DB::table('cart_items')->where('cart_id', $fresh->id)->count(),
            'Новая корзина не получила позицию.'
        );
    }

    // ── Фикстуры ────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return [CartToken::HEADER => self::TOKEN];
    }

    /** @return array{0: int, 1: int} [sessionId, inventoryItemId] */
    private function seedSellable(): array
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: self::CAPACITY,
            priceAmount: self::PRICE,
        );

        return [$sessionId, $inventoryItemId];
    }

    private function addItem(int $sessionId, int $inventoryItemId, int $quantity = 1)
    {
        return $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => $quantity,
        ], [CartToken::HEADER => self::TOKEN]);
    }
}
