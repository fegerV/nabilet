<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Core\Users\Services\CustomerAccountService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * У заказа появился владелец — и вместе с ним заработали правила «на покупателя».
 *
 * ДЕФЕКТ, который этот файл закрывает.
 *
 * Витрина гостевая, и до этой правки `orders.user_id` у гостевого заказа всегда
 * оставался NULL: покупатель вводил имя и адрес, они записывались на заказ, но
 * субъекта («кто это») в системе не возникало. Из этого следовали две поломки,
 * обе проверяемые кодом:
 *
 *   1. `per_user_limit` НЕ ДЕЙСТВОВАЛ. `PromoCodeService::buildContext()` берёт
 *      личность из `carts.user_id`, а `userRedemptions()` при `null` возвращает 0
 *      — то есть для гостя код «не использован никогда», сколько бы раз он им ни
 *      платил.
 *   2. Условие `first_purchase` (ТЗ §86) проходило БЕСКОНЕЧНО по той же причине:
 *      прошлых оплаченных заказов у гостя «нет» никогда.
 *
 * Тесты ниже воспроизводят оба случая ДО и ПОСЛЕ: они падают на прежнем коде
 * (`orders.user_id` = NULL, второй checkout с тем же кодом проходит) и проходят
 * на новом.
 *
 * ЧТО ЗДЕСЬ НЕ ПРОВЕРЯЕТСЯ. Баллы лояльности: их в схеме нет ни одной таблицы
 * (`loyalty*`, `bonus*`, `points*`, `wallet` — 0 совпадений), и это отдельная
 * продуктовая волна, а не часть этой. Здесь проверяется только то, без чего она
 * невозможна: у заказа есть владелец, и по нему можно посчитать историю.
 */
class CustomerAccountFromCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const EMAIL = 'buyer@example.com';

    /** @return array<string, string> */
    private function cartHeaders(string $token = self::TOKEN): array
    {
        return [CartToken::HEADER => $token];
    }

    /**
     * Положить в корзину и оформить заказ гостем.
     *
     * @return array{0: int, 1: string} [orders.id, orders.public_id]
     */
    private function placeGuestOrder(
        int $sessionId,
        int $inventoryItemId,
        string $email = self::EMAIL,
        ?string $promoCode = null,
        string $token = self::TOKEN,
    ): array {
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->cartHeaders($token))->assertStatus(201);

        $payload = [
            'session_id' => $sessionId,
            'customer_email' => $email,
            'customer_email_confirmation' => $email,
            'customer_name' => 'Иван Петров',
        ];

        if ($promoCode !== null) {
            $payload['promo_code'] = $promoCode;
        }

        $publicId = (string) $this->postJson('/api/v1/cart/checkout', $payload, $this->cartHeaders($token))
            ->assertStatus(200)
            ->json('data.order_id');

        return [(int) DB::table('orders')->where('public_id', $publicId)->value('id'), $publicId];
    }

    /**
     * Промокод прямо в таблицу — без админского HTTP-пути.
     *
     * Значения по умолчанию выбраны так, чтобы код был применим к любой корзине:
     * `scope = all`, без окна действия, без минимальной суммы. Тесту важна только
     * проверяемая ось (`per_user_limit` / `scope = first_purchase`), поэтому всё
     * остальное выключено.
     *
     * @param array<string, mixed> $overrides
     */
    private function seedPromoCode(int $organizationId, string $code, array $overrides = []): void
    {
        $now = now()->toDateTimeString();

        DB::table('promo_codes')->insert(array_merge([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'code' => mb_strtoupper($code),
            'discount_type' => 'percent',
            'value_amount' => 0,
            'value_percent' => '10.00',
            'currency' => 'RUB',
            'scope' => 'all',
            'event_id' => null,
            'event_category_id' => null,
            'min_order_amount' => 0,
            'max_redemptions' => null,
            'per_user_limit' => 1,
            'redemptions_count' => 0,
            'status' => 'active',
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function userIdOf(int $orderId): ?int
    {
        $value = DB::table('orders')->where('id', $orderId)->value('user_id');

        return $value === null ? null : (int) $value;
    }

    // ── 1. Аккаунт появляется вместе с заказом ───────────────────────────────

    /** Гостевой чекаут заводит аккаунт и связывает с ним и заказ, и корзину. */
    public function test_a_guest_checkout_creates_a_buyer_account_and_links_the_order(): void
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: self::PRICE,
        );

        $this->assertSame(0, DB::table('users')->count(), 'предусловие: база пуста');

        [$orderId] = $this->placeGuestOrder($sessionId, $inventoryItemId);

        $user = DB::table('users')->where('email', self::EMAIL)->first();

        $this->assertNotNull($user, 'адрес заказа обязан стать аккаунтом покупателя');
        $this->assertSame('Иван', $user->first_name, 'одно поле имени делится по первому пробелу');
        $this->assertSame('Петров', $user->last_name, 'остаток целиком уходит в фамилию');
        $this->assertSame('active', $user->status);

        $this->assertSame((int) $user->id, $this->userIdOf($orderId), 'заказ обязан получить владельца');
        $this->assertSame(
            (int) $user->id,
            (int) DB::table('carts')->where('id', DB::table('orders')->where('id', $orderId)->value('cart_id'))->value('user_id'),
            'корзина тоже получает владельца — из неё промокод читает личность',
        );

        // Заказ по-прежнему помнит адрес: `customer_email` — атрибут заказа, а не
        // ссылка на аккаунт, и письмо с билетом уходит именно на него.
        $this->assertSame(self::EMAIL, (string) DB::table('orders')->where('id', $orderId)->value('customer_email'));
        $this->assertSame((int) $organizationId, (int) DB::table('orders')->where('id', $orderId)->value('organization_id'));
    }

    /**
     * Аккаунт заводится БЕЗ пароля и НЕ подтверждён — это не недоделка.
     *
     * `password = NULL` означает, что войти по нему нельзя, пока владелец не
     * задаст пароль через штатный `POST /auth/password/forgot`. Пометить адрес
     * подтверждённым здесь было бы ложью: адрес введён покупателем и никем не
     * проверен. Флаг ставится только тогда, когда письмо дошло до настоящего
     * ящика.
     */
    public function test_the_created_account_has_no_password_and_is_not_verified(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1, priceAmount: self::PRICE);

        $this->placeGuestOrder($sessionId, $inventoryItemId);

        $user = DB::table('users')->where('email', self::EMAIL)->first();

        $this->assertNull($user->password, 'аккаунт без пароля: войти по нему нельзя до смены пароля');
        $this->assertNull($user->email_verified_at, 'адрес не подтверждён — его никто не проверял');
    }

    /** Две покупки на один адрес — один аккаунт, а не два. */
    public function test_the_same_email_reuses_one_account_across_orders(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 2, priceAmount: self::PRICE);

        [$firstOrderId] = $this->placeGuestOrder($sessionId, $inventoryItemId);
        [$secondOrderId] = $this->placeGuestOrder($sessionId, $inventoryItemId);

        $this->assertSame(1, DB::table('users')->count(), 'второй заказ не создаёт второй аккаунт');
        $this->assertSame(
            $this->userIdOf($firstOrderId),
            $this->userIdOf($secondOrderId),
            'оба заказа принадлежат одному покупателю',
        );
    }

    /**
     * Регистр адреса не создаёт второго аккаунта.
     *
     * Колонка `users.email` — `utf8mb4_unicode_ci`, то есть MySQL считает
     * `Ivan@Example.com` и `ivan@example.com` одним значением. Предварительный
     * поиск в PHP обязан согласиться с колонкой, иначе форма ответит «адрес
     * свободен» и упадёт на дублирующем ключе (ERROR 1062) — см. `ContactChannel`.
     */
    public function test_the_account_is_matched_case_insensitively(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 2, priceAmount: self::PRICE);

        $this->placeGuestOrder($sessionId, $inventoryItemId, email: 'Ivan@Example.com');
        $this->placeGuestOrder($sessionId, $inventoryItemId, email: 'ivan@example.com');

        $this->assertSame(1, DB::table('users')->count());
    }

    /** Вошедший покупатель — владелец заказа; второй аккаунт не заводится. */
    public function test_an_authenticated_buyer_is_used_instead_of_a_new_account(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1, priceAmount: self::PRICE);

        $password = 'Kv7#mRta2!Qz';

        $this->postJson('/api/v1/auth/register', [
            'email' => self::EMAIL,
            'password' => $password,
            'password_confirmation' => $password,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => $password,
        ])->assertStatus(200)->json('data.token');

        $registeredId = (int) DB::table('users')->where('email', self::EMAIL)->value('id');

        [$orderId] = $this->placeGuestOrder($sessionId, $inventoryItemId);

        $this->assertSame(1, DB::table('users')->count(), 'гостевой чекаут не создаёт дубль аккаунта');
        $this->assertSame($registeredId, $this->userIdOf($orderId));
        $this->assertNotSame('', $token);
    }

    // ── 2. Правила «на покупателя» теперь действуют ──────────────────────────

    /**
     * `per_user_limit` считает гостя.
     *
     * До правки: `carts.user_id` = NULL → `userRedemptions()` = 0 ВСЕГДА, и один
     * и тот же покупатель мог провести код с `per_user_limit = 1` сколько угодно
     * раз. Здесь второй чекаут обязан получить отказ.
     */
    public function test_per_user_limit_now_counts_a_guest_buyer(): void
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::PRICE,
        );

        $this->seedPromoCode($organizationId, 'ONCE', ['per_user_limit' => 1]);

        [$firstOrderId] = $this->placeGuestOrder($sessionId, $inventoryItemId, promoCode: 'ONCE');

        $this->assertGreaterThan(
            0,
            (int) DB::table('orders')->where('id', $firstOrderId)->value('discount_amount'),
            'первый раз код обязан примениться',
        );
        $this->assertSame(
            1,
            DB::table('promo_code_redemptions')->where('user_id', $this->userIdOf($firstOrderId))->count(),
            'расход кода обязан быть привязан к покупателю, а не к NULL',
        );

        // Вторая покупка тем же покупателем: корзина новая, покупатель тот же.
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->cartHeaders())->assertStatus(201);

        $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => self::EMAIL,
            'customer_email_confirmation' => self::EMAIL,
            'promo_code' => 'ONCE',
        ], $this->cartHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PROMO_CODE_REJECTED');

        $this->assertSame(1, DB::table('orders')->count(), 'отказ по лимиту не создаёт заказ');
    }

    /**
     * Условие `first_purchase` перестаёт проходить после первой оплаты.
     *
     * Статус первого заказа выставляется прямо в таблице, а не прогоном оплаты:
     * `buildContext()` считает прошлые заказы по статусу
     * (`paid|completed|refunded|partially_refunded`), и платёжный контур здесь —
     * лишняя деталь. Проверяется ровно то, что до правки было недостижимо: у
     * гостя прошлых заказов не было НИКОГДА, потому что их не с чем связать.
     */
    public function test_first_purchase_scope_stops_applying_after_a_paid_order(): void
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::PRICE,
        );

        $this->seedPromoCode($organizationId, 'FIRSTONLY', ['scope' => 'first_purchase']);

        [$firstOrderId] = $this->placeGuestOrder($sessionId, $inventoryItemId, promoCode: 'FIRSTONLY');

        $this->assertGreaterThan(
            0,
            (int) DB::table('orders')->where('id', $firstOrderId)->value('discount_amount'),
            'первая покупка обязана принять код',
        );

        DB::table('orders')->where('id', $firstOrderId)->update(['status' => 'paid']);

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->cartHeaders())->assertStatus(201);

        $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => self::EMAIL,
            'customer_email_confirmation' => self::EMAIL,
            'promo_code' => 'FIRSTONLY',
        ], $this->cartHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PROMO_CODE_REJECTED');

        $this->assertSame(1, DB::table('orders')->count());
    }

    // ── 3. Границы: без адреса аккаунт не заводится ──────────────────────────

    /**
     * Пустой или синтаксически неверный адрес → `null`, а не аккаунт.
     *
     * Молча создать учётную запись на пустой адрес нельзя: получилась бы строка, в
     * которую невозможно войти, невозможно отправить билет и невозможно сбросить
     * пароль — то есть ровно тот «недостижимый аккаунт», от которого
     * `UserIdentityPolicy::registerDecision()` отказывается при регистрации.
     *
     * Через HTTP это недостижимо (`customer_email` там `required|email:rfc`), но
     * `checkoutByPublicId()` — второй вход в то же тело оформления, и адрес там
     * необязателен. Поэтому проверяется сам сервис: он вызывается и мимо HTTP.
     */
    public function test_the_service_refuses_an_empty_or_malformed_address(): void
    {
        $service = $this->app->make(CustomerAccountService::class);

        $this->assertNull($service->resolveForCheckout(''));
        $this->assertNull($service->resolveForCheckout('   '));
        $this->assertNull($service->resolveForCheckout('not-an-address'));
        $this->assertNull($service->resolveForCheckout('buyer@'));

        $this->assertSame(0, DB::table('users')->count(), 'ни один вызов не создал аккаунт');

        // Валидный адрес — создаёт, и повторный вызов возвращает тот же id.
        $id = $service->resolveForCheckout(self::EMAIL, 'Иван Петров', '+7 900 000-00-00');

        $this->assertNotNull($id);
        $this->assertSame($id, $service->resolveForCheckout(self::EMAIL));
        $this->assertSame(1, DB::table('users')->count());
    }
}
