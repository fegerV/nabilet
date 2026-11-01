<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\PromoCodeRedemption;
use Nabilet\Modules\Webhooks\Models\WebhookDelivery;
use Nabilet\Modules\Orders\Models\OrderItem;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\PaymentTransaction;
use Nabilet\Modules\Payments\Models\Refund;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Tickets\Models\OfflineBundle;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketScan;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Read-side поведение `TenantScoped` (фаза 2).
 *
 * Проверяет сам механизм глобального скоупа на модели с включённым скоупом:
 *   - с контекстом фильтрует выборку по организации;
 *   - без контекста вне `withoutScope()` — `TenantContextMissingError` (fail-closed);
 *   - внутри `OrganizationContext::withoutScope()` — видно всё (системный контекст).
 */

/**
 * Тестовая модель с включённым глобальным скоупом.
 */
class ScopedOrder extends Order
{
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }
}

final class TenantScopedReadTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    protected function tearDown(): void
    {
        app(OrganizationContext::class)->clear();

        parent::tearDown();
    }

    private function organization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Org',
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(int $organizationId): Order
    {
        $order = new Order();
        $order->fill([
            'organization_id' => $organizationId,
            'subtotal_amount' => 0,
            'total_amount' => 0,
            'customer_email' => 'buyer@example.com',
        ]);
        $order->save();

        return $order;
    }

    public function test_scope_filters_by_the_active_organization(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeOrder($orgA);
        $this->makeOrder($orgB);

        app(OrganizationContext::class)->set((string) $orgA);

        $this->assertSame(1, ScopedOrder::query()->count());
    }

    public function test_scope_throws_without_context_unless_bypassing(): void
    {
        $org = $this->organization();
        $this->makeOrder($org);

        try {
            ScopedOrder::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            1,
            app(OrganizationContext::class)->withoutScope(fn () => ScopedOrder::query()->count()),
            'withoutScope() обязан видеть все строки'
        );
    }

    /**
     * Скоуп оплачивается на НАСТОЯЩЕЙ модели: `Payment` включает читающий
     * скоуп сам (`tenantScopeEnabled(): true`), без тестовой обёртки.
     *
     * Платежи пишутся прямо в таблицу: здесь важен только читающий контур,
     * а он не зависит от того, как появилась строка.
     */
    public function test_payment_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makePayment($this->makeOrder($orgA), $orgA);
        $this->makePayment($this->makeOrder($orgB), $orgB);

        // Без контекста — отказ, а не «показать всё».
        try {
            Payment::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, Payment::query()->count(), 'чужие платежи не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => Payment::query()->count()),
            'withoutScope() обязан видеть платежи всех организаций'
        );
    }

    private function makePayment(Order $order, int $organizationId): void
    {
        $now = now()->toDateTimeString();

        DB::table('payments')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'order_id' => $order->id,
            'provider' => 'yookassa',
            'amount' => $order->total_amount,
            'currency' => 'RUB',
            'status' => 'pending',
            'idempotency_key' => (string) Str::ulid()->toBase32(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Скоуп оплачивается на НАСТОЯЩЕЙ модели: `Ticket` включает читающий скоуп
     * сам (`tenantScopeEnabled(): true`), без тестовой обёртки.
     *
     * Билет — сын заказа и позиций склада: вся FK-цепочка (event → session →
     * inventory → order_item → ticket) собирается фикстурой, строки пишутся
     * прямо в таблицу — здесь важен только читающий контур.
     */
    public function test_ticket_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeTicket($orgA);
        $this->makeTicket($orgB);

        // Без контекста — отказ, а не «показать все билеты всех арендаторов».
        try {
            Ticket::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, Ticket::query()->count(), 'чужие билеты не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => Ticket::query()->count()),
            'withoutScope() обязан видеть билеты всех организаций'
        );
    }

    /**
     * Скоуп `TicketScan`: журнал сканов — финансово-доступовый след на входе.
     * Сын билета, строки пишутся прямо в таблицу поверх `makeTicket()`.
     */
    public function test_ticket_scan_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $ticketA = $this->makeTicket($orgA);
        $this->makeScan($ticketA, $orgA);
        $ticketB = $this->makeTicket($orgB);
        $this->makeScan($ticketB, $orgB);

        // Без контекста — отказ, а не «показать все сканы».
        try {
            TicketScan::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, TicketScan::query()->count(), 'чужие сканы не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => TicketScan::query()->count()),
            'withoutScope() обязан видеть сканы всех организаций'
        );
    }

    /**
     * Скоуп оплачивается на НАСТОЯЩЕЙ модели: `Refund` включает читающий скоуп
     * сам (`tenantScopeEnabled(): true`).
     *
     * Возврат — сын платежа и заказа: обе строки создаются тут же; здесь важен
     * только читающий контур, а он не зависит от того, как появились строки.
     */
    public function test_refund_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeRefund($orgA);
        $this->makeRefund($orgB);

        // Без контекста — отказ, а не «показать все возвраты».
        try {
            Refund::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, Refund::query()->count(), 'чужие возвраты не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => Refund::query()->count()),
            'withoutScope() обязан видеть возвраты всех организаций'
        );
    }

    /**
     * Скоуп `CheckinDevice`: устройства чекеров — ключ доступа к журналам
     * сканов и офлайн-бандлам своего арендатора. Строки пишутся прямо в
     * таблицу: здесь важен только читающий контур.
     */
    public function test_checkin_device_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeDevice($orgA);
        $this->makeDevice($orgB);

        // Без контекста — отказ, а не «показать все устройства».
        try {
            CheckinDevice::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, CheckinDevice::query()->count(), 'чужие устройства не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => CheckinDevice::query()->count()),
            'withoutScope() обязан видеть устройства всех организаций'
        );
    }

    /**
     * Скоуп `OfflineBundle`: бандл — подписанный снимок билетов, уезжающий
     * на устройство чекера; чужой бандл = вся аудитория зала у чужого
     * устройства. Сын устройства и события, строки пишутся прямо в таблицу.
     */
    public function test_offline_bundle_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeBundle($orgA);
        $this->makeBundle($orgB);

        // Без контекста — отказ, а не «показать все бандлы».
        try {
            OfflineBundle::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, OfflineBundle::query()->count(), 'чужие бандлы не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => OfflineBundle::query()->count()),
            'withoutScope() обязан видеть бандлы всех организаций'
        );
    }

    /**
     * Скоуп `PaymentTransaction`: карточка процессинга (authorization/capture/
     * refund/failure) — сын платежа. Строки пишутся прямо в таблицу поверх
     * свежего платежа: здесь важен только читающий контур.
     */
    public function test_payment_transaction_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeTransaction($orgA);
        $this->makeTransaction($orgB);

        // Без контекста — отказ, а не «показать все транзакции».
        try {
            PaymentTransaction::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, PaymentTransaction::query()->count(), 'чужие транзакции не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => PaymentTransaction::query()->count()),
            'withoutScope() обязан видеть транзакции всех организаций'
        );
    }

    private function makeTransaction(int $organizationId): void
    {
        $order = $this->makeOrder($organizationId);
        $now = now()->toDateTimeString();

        $paymentId = (int) DB::table('payments')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'order_id' => $order->id,
            'provider' => 'yookassa',
            'amount' => $order->total_amount,
            'currency' => 'RUB',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::ulid()->toBase32(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('payment_transactions')->insert([
            'payment_id' => $paymentId,
            'organization_id' => $organizationId,
            'type' => 'authorization',
            'created_at' => $now,
        ]);
    }

    /**
     * Скоуп `OrderItem`: позиция заказа — сын заказа и строки склада. Строки
     * пишутся прямо в таблицу (как в makeTicket): здесь важен только читающий
     * контур.
     */
    public function test_order_item_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeOrderItem($orgA);
        $this->makeOrderItem($orgB);

        // Без контекста — отказ, а не «показать все позиции».
        try {
            OrderItem::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, OrderItem::query()->count(), 'чужие позиции не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => OrderItem::query()->count()),
            'withoutScope() обязан видеть позиции всех организаций'
        );
    }

    private function makeOrderItem(int $organizationId): void
    {
        [$orgId, , , $inventoryId] = $this->seedSellableSeat(
            available: 2,
            organizationId: $organizationId,
        );
        \assert($orgId === $organizationId);

        $order = $this->makeOrder($organizationId);
        $now = now()->toDateTimeString();

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'organization_id' => $organizationId,
            'inventory_item_id' => $inventoryId,
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
            'event_title_snapshot' => 'Тестовое событие',
            'created_at' => $now,
        ]);
    }

    /**
     * Скоуп `SeatHold`: держка места — ребёнок корзины гостя (capability
     * токена корзины, не организация HTTP-запроса). Корзина собирается здесь
     * же из сеанса той же FK-цепочки; держка пишется прямо в таблицу.
     */
    public function test_seat_hold_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeSeatHold($orgA);
        $this->makeSeatHold($orgB);

        // Без контекста — отказ, а не «показать все держки».
        try {
            SeatHold::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, SeatHold::query()->count(), 'чужие держки не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => SeatHold::query()->count()),
            'withoutScope() обязан видеть держки всех организаций'
        );
    }

    private function makeSeatHold(int $organizationId): void
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(
            available: 2,
            organizationId: $organizationId,
        );
        \assert($orgId === $organizationId);

        $now = now()->toDateTimeString();

        // status берёт дефолт 'active'; expires_at держки = TTL корзины.
        $cartId = (int) DB::table('carts')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('seat_holds')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'inventory_item_id' => $inventoryId,
            'session_id' => $sessionId,
            'cart_id' => $cartId,
            'quantity' => 1,
            'expires_at' => now()->addMinutes(15)->toDateTimeString(),
            'created_at' => $now,
        ]);
    }

    /**
     * Скоуп `CartItem`: позиция корзины — ребёнок корзины гостя (capability
     * токена корзины, не организация HTTP-запроса). Позиции маркируются
     * ценой из позиции склада той же FK-цепочки.
     */
    public function test_cart_item_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeCartItem($orgA);
        $this->makeCartItem($orgB);

        // Без контекста — отказ, а не «показать все позиции корзин».
        try {
            CartItem::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, CartItem::query()->count(), 'чужие позиции корзин не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => CartItem::query()->count()),
            'withoutScope() обязан видеть позиции корзин всех организаций'
        );
    }

    /**
     * Скоуп `Cart`: корзина гостя — capability токена корзины (не организация
     * HTTP-запроса). Корзина маркируется сеансом той же FK-цепочки.
     */
    public function test_cart_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeCart($orgA);
        $this->makeCart($orgB);

        // Без контекста — отказ, а не «показать все корзины».
        try {
            Cart::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, Cart::query()->count(), 'чужие корзины не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => Cart::query()->count()),
            'withoutScope() обязан видеть корзины всех организаций'
        );
    }

    /**
     * Скоуп `PromoCodeRedemption`: расход промокода — ребёнок пары
     * (промокод, заказ) одной организации.
     */
    public function test_promo_code_redemption_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makePromoCodeRedemption($orgA);
        $this->makePromoCodeRedemption($orgB);

        // Без контекста — отказ, а не «показать все расходы промокодов».
        try {
            PromoCodeRedemption::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, PromoCodeRedemption::query()->count(), 'чужие расходы не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => PromoCodeRedemption::query()->count()),
            'withoutScope() обязан видеть расходы всех организаций'
        );
    }

    /**
     * Скоуп `WebhookDelivery`: доставка — ребёнок вебхука организации.
     */
    public function test_webhook_delivery_read_scope_is_native(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        $this->makeWebhookDelivery($orgA);
        $this->makeWebhookDelivery($orgB);

        // Без контекста — отказ, а не «показать все доставки».
        try {
            WebhookDelivery::query()->count();
            $this->fail('Ожидался TenantContextMissingError без контекста');
        } catch (TenantContextMissingError $e) {
            $this->addToAssertionCount(1);
        }

        app(OrganizationContext::class)->set((string) $orgA);
        $this->assertSame(1, WebhookDelivery::query()->count(), 'чужие доставки не видны');

        $this->assertSame(
            2,
            app(OrganizationContext::class)->withoutScope(fn () => WebhookDelivery::query()->count()),
            'withoutScope() обязан видеть доставки всех организаций'
        );
    }

    private function makeCart(int $organizationId): void
    {
        [$orgId, $eventId, $sessionId] = $this->seedSellableSeat(
            available: 2,
            organizationId: $organizationId,
        );
        \assert($orgId === $organizationId);

        $now = now()->toDateTimeString();

        // status берёт дефолт 'active' — активная корзина.
        DB::table('carts')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makePromoCodeRedemption(int $organizationId): void
    {
        $order = $this->makeOrder($organizationId);
        $now = now()->toDateTimeString();

        // Дефолты promo_codes (percent 0, scope 'all', статус 'active') покрывают
        // всё, кроме ключей и меток; уникальность (организация, код) соблюдается
        // тем, что каждая строка — в своей организации.
        $promoId = (int) DB::table('promo_codes')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'code' => 'TESTCODE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('promo_code_redemptions')->insert([
            'promo_code_id' => $promoId,
            'order_id' => $order->id,
            'user_id' => null,
            'discount_amount' => 100,
            'redeemed_at' => $now,
            'organization_id' => $organizationId,
        ]);
    }

    private function makeWebhookDelivery(int $organizationId): void
    {
        $now = now()->toDateTimeString();

        // secret_encrypted в тесте — произвольный текст: джоба расшифровывает
        // его только при сетевом вызове, читающий скоуп это не затрагивает.
        $webhookId = (int) DB::table('webhooks')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'url' => 'https://partner.example.test/hook',
            'secret_encrypted' => 'not-a-real-secret',
            'events_json' => json_encode(['order.paid']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('webhook_deliveries')->insert([
            'webhook_id' => $webhookId,
            'delivery_id' => (string) Str::uuid(),
            'event_name' => 'order.paid',
            'payload_json' => json_encode(['ok' => true]),
            'created_at' => $now,
            'organization_id' => $organizationId,
        ]);
    }

    private function makeCartItem(int $organizationId): void
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(
            available: 2,
            organizationId: $organizationId,
        );
        \assert($orgId === $organizationId);

        $now = now()->toDateTimeString();

        // status берёт дефолт 'active' — активная корзина.
        $cartId = (int) DB::table('carts')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'session_id' => $sessionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('cart_items')->insert([
            'cart_id' => $cartId,
            'organization_id' => $organizationId,
            'inventory_item_id' => $inventoryId,
            'quantity' => 1,
            'unit_price' => 100,
            'total_price' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makeRefund(int $organizationId): void
    {
        $order = $this->makeOrder($organizationId);
        $now = now()->toDateTimeString();

        $paymentId = (int) DB::table('payments')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'order_id' => $order->id,
            'provider' => 'yookassa',
            'amount' => $order->total_amount,
            'currency' => 'RUB',
            'status' => 'succeeded',
            'idempotency_key' => (string) Str::ulid()->toBase32(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('refunds')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'order_id' => $order->id,
            'payment_id' => $paymentId,
            'amount' => $order->total_amount,
            'currency' => 'RUB',
            'status' => 'requested',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makeTicket(int $organizationId): int
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(
            available: 2,
            organizationId: $organizationId,
        );
        \assert($orgId === $organizationId);

        $order = $this->makeOrder($organizationId);

        $now = now()->toDateTimeString();

        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'organization_id' => $organizationId,
            'inventory_item_id' => $inventoryId,
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
            'event_title_snapshot' => 'Тестовое событие',
            'created_at' => $now,
        ]);

        return (int) DB::table('tickets')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'ticket_number' => 'TCK-' . strtoupper(Str::random(10)),
            'order_id' => $order->id,
            'order_item_id' => $orderItemId,
            'event_id' => $eventId,
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryId,
            'qr_token_hash' => hash('sha256', (string) Str::ulid()),
            'issued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makeScan(int $ticketId, int $organizationId): void
    {
        $sessionId = (int) DB::table('tickets')->where('id', $ticketId)->value('session_id');
        $now = now()->toDateTimeString();

        DB::table('ticket_scans')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'ticket_id' => $ticketId,
            'session_id' => $sessionId,
            'mode' => 'online',
            'result' => 'admitted',
            'scanned_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function makeDevice(int $organizationId): int
    {
        $now = now()->toDateTimeString();

        // status/platform/app_version берут дефолты колонок; хеш токена
        // уникален сам по себе, как у боевого выпускающего (DeviceTokenIssuer).
        return (int) DB::table('checkin_devices')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Чекер ' . strtoupper(Str::random(6)),
            'device_token_hash' => hash('sha256', (string) Str::ulid()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Бандл — сын устройства (makeDevice) с сеансом из того же арендатора
     * (seedSellableSeat). Схема_version/counts/status берут дефолты колонок;
     * hash бандла уникален сам по себе, как у боевого OfflineBundleBuilder
     * (device-scoped Sha256Hex).
     */
    private function makeBundle(int $organizationId): void
    {
        [, $eventId, $sessionId] = $this->seedSellableSeat(
            available: 2,
            organizationId: $organizationId,
        );

        $now = now()->toDateTimeString();

        DB::table('offline_bundles')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'checkin_device_id' => $this->makeDevice($organizationId),
            'event_id' => $eventId,
            'session_id' => $sessionId,
            'bundle_hash' => hash('sha256', (string) Str::ulid()),
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
