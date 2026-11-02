<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Гостевая оплата: capability-токен корзины на `POST /payments`.
 *
 * ЧТО БЫЛО. Риск из журнала §2.1 (`PaymentController::store` вне `auth:api`):
 * со включённым читающим скоупом `Order` гость получал на этом маршруте 500 —
 * у гостя тенант-контекста нет, а `resolveOrder()` читал заказ БЕЗ bypass, и
 * fail-closed скоуп отвечал `TenantContextMissingError` там, где контракт ждёт
 * платёжную ссылку или 404. Регрессия не ловилась ни одним тестом: вся
 * существующая оплата ходила администратором, гостевая ветка `mayPay()` была
 * не покрыта.
 *
 * ПОСЛЕ ФИКСА. Поиск заказа — в bypass; доступ решает только `mayPay()`:
 * сотрудник — по организации заказа, владелец — по `user_id`, гость — по
 * capability-токену корзины. Чужой заказ — 404 для всех троих (IDOR-запрет),
 * и попытка не создаёт ни одной строки в `payments`.
 */
class PaymentGuestAccessTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;
    use StaffAuth;

    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const FOREIGN_TOKEN = '99999999-8888-7777-6666-555555555555';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.payment.demo_mode' => true]);

        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => new ScriptedPaymentProvider());
    }

    /**
     * Гость с capability-токеном начинает оплату — финальный шаг витринного
     * сценария «выбрать место → корзина → оплатить». Раньше запрос падал 500-й
     * на скоупнутом поиске заказа ещё до проверки права.
     */
    public function test_a_guest_with_the_cart_token_can_start_the_payment(): void
    {
        $publicId = $this->placeGuestOrder();

        $this->postJson('/api/v1/payments', [
            'order_id' => $publicId,
            'idempotency_key' => 'guest_capability_key',
        ], [CartToken::HEADER => self::TOKEN])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('payments')->count());
    }

    /**
     * Без capability-токена — 404, а не 500: гостевая ветка обязана вести себя
     * так же, как и «не нашёлся заказ» (IDOR-запрет: перебор `order_id` не
     * должен отличать чужой заказ от несуществующего).
     */
    public function test_a_guest_without_the_token_gets_a_404(): void
    {
        $publicId = $this->placeGuestOrder();

        $this->postJson('/api/v1/payments', [
            'order_id' => $publicId,
            'idempotency_key' => 'guest_no_token',
        ])->assertStatus(404)->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        $this->assertSame(0, DB::table('payments')->count());
    }

    /** Чужой capability-токен не открывает чужой заказ. */
    public function test_a_foreign_cart_token_gets_a_404(): void
    {
        $publicId = $this->placeGuestOrder();

        $this->postJson('/api/v1/payments', [
            'order_id' => $publicId,
            'idempotency_key' => 'guest_foreign_token',
        ], [CartToken::HEADER => self::FOREIGN_TOKEN])
            ->assertStatus(404);

        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * Сотрудник другой организации: bypass-поиск находит чужой заказ, но
     * `mayPay()` отказывает — оплата чужого заказа недостижима (404), и
     * платёжная ссылка на чужой заказ не выдаётся.
     */
    public function test_a_staff_of_another_organization_cannot_pay_a_foreign_order(): void
    {
        $publicId = $this->placeGuestOrder();
        $this->actingAsStaff($this->otherOrganization());

        $this->postJson('/api/v1/payments', [
            'order_id' => $publicId,
            'idempotency_key' => 'foreign_staff_key',
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * Гостевой заказ через корзину: место → корзина → checkout под токеном.
     * Ровно тот путь, на котором у заказа появляются `cart_id` AND `cart_token`
     * — capability, которую проверяет `mayPay()`.
     *
     * @return string `public_id` заказа — то, чем витрина адресует оплату
     */
    private function placeGuestOrder(string $token = self::TOKEN): string
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], [CartToken::HEADER => $token])->assertStatus(201);

        return (string) $this->postJson('/api/v1/cart/checkout', [
            'session_id' => $sessionId,
            'customer_email' => 'buyer@example.com',
            'customer_email_confirmation' => 'buyer@example.com',
            'customer_name' => 'Покупатель',
        ], [CartToken::HEADER => $token])->assertStatus(200)->json('data.order_id');
    }

    /** Второй арендатор для проверки кросс-организационного отказа. */
    private function otherOrganization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Соседняя организация',
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
