<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\Refund;
use Nabilet\Modules\Tickets\Models\Ticket;
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

    private function makeTicket(int $organizationId): void
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

        DB::table('tickets')->insert([
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
}
