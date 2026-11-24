<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Защита от oversell: два покупателя, одно последнее место.
 *
 * В одном PHPUnit-процессе настоящие потоки недоступны, а `RefreshDatabase`
 * держит все соединения на одном PDO. Поэтому гонка моделируется детерминированно:
 * на первом же чтении сеанса внутри транзакции первого запроса внедряется
 * «второй покупатель» — точный такой же атомарный UPDATE с условием `>=`.
 * Он фиксируется раньше, чем первый запрос дойдёт до своего UPDATE, и первый
 * обязан получить 409, а не уйти в минус.
 */
final class ConcurrentBookingTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const TOKEN_A = '11111111-2222-3333-4444-555555555555';
    private const TOKEN_B = '99999999-8888-7777-6666-555555555555';

    public function test_a_competing_decrement_makes_the_second_reservation_fail(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(1);

        $injected = false;
        $competitorAffected = null;
        $reservationSql = [];

        DB::listen(function ($query) use (&$injected, &$competitorAffected, &$reservationSql, $inventoryItemId): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'update') && str_contains($sql, 'available_quantity')) {
                $reservationSql[] = $query->sql;
            }
            // `exists:sessions,id` из валидации идёт по id; сервис ищет и по
            // public_id. Внедряемся именно на чтении сервиса, внутри его транзакции.
            if ($injected || $query->connectionName !== 'mysql'
                || ! str_contains($sql, '`sessions`') || ! str_contains($sql, 'public_id')) {
                return;
            }
            $injected = true;
            $competitorAffected = DB::table('inventory_items')
                ->where('id', $inventoryItemId)
                ->where('available_quantity', '>=', 1)
                ->decrement('available_quantity', 1);
        });

        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], [CartToken::HEADER => self::TOKEN_A])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SEAT_UNAVAILABLE');

        self::assertTrue($injected, 'конкурент должен был стартовать до UPDATE первого запроса');
        self::assertSame(1, $competitorAffected, 'конкурент успел забрать последнее место');
        self::assertNotEmpty($reservationSql, 'резерв обязан идти через UPDATE, а не check-then-write');
        foreach ($reservationSql as $sql) {
            self::assertStringContainsString('available_quantity', $sql);
            self::assertStringContainsString('>=', $sql);
        }

        // Неудавшийся запрос откатил свой холд; место снова доступно.
        self::assertSame(0, (int) DB::table('seat_holds')->where('inventory_item_id', $inventoryItemId)->count());
        self::assertSame(1, (int) DB::table('inventory_items')->where('id', $inventoryItemId)->value('available_quantity'));
    }

    public function test_two_buyers_get_exactly_one_hold_for_one_seat(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(1);
        $body = [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ];

        $this->postJson('/api/v1/cart/items', $body, [CartToken::HEADER => self::TOKEN_A])
            ->assertCreated();
        $this->postJson('/api/v1/cart/items', $body, [CartToken::HEADER => self::TOKEN_B])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SEAT_UNAVAILABLE');

        self::assertSame(1, (int) DB::table('seat_holds')
            ->where('inventory_item_id', $inventoryItemId)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->count());
        self::assertSame(0, (int) DB::table('inventory_items')->where('id', $inventoryItemId)->value('available_quantity'));
        self::assertSame('sold_out', (string) DB::table('inventory_items')->where('id', $inventoryItemId)->value('status'));
    }

    public function test_standing_capacity_is_shared_and_never_goes_negative(): void
    {
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(2);
        $body = [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ];

        $this->postJson('/api/v1/cart/items', $body, [CartToken::HEADER => self::TOKEN_A])->assertCreated();
        $this->postJson('/api/v1/cart/items', $body, [CartToken::HEADER => self::TOKEN_B])->assertCreated();
        $this->postJson('/api/v1/cart/items', $body, [
            CartToken::HEADER => '77777777-6666-5555-4444-333333333333',
        ])->assertStatus(409)->assertJsonPath('error.code', 'SEAT_UNAVAILABLE');

        self::assertSame(2, (int) DB::table('seat_holds')
            ->where('inventory_item_id', $inventoryItemId)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->count());
        self::assertSame(2, (int) DB::table('seat_holds')
            ->where('inventory_item_id', $inventoryItemId)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->sum('quantity'));
        self::assertSame(0, (int) DB::table('inventory_items')->where('id', $inventoryItemId)->value('available_quantity'));
    }
}
