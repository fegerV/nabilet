<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «Сколько билетов осталось» на публичной карточке события.
 *
 * ПОЧЕМУ ЭТОТ ТЕСТ СУЩЕСТВУЕТ
 *
 * Карточка события и каталог считали доступность как `COUNT(*)` строк
 * `inventory_items` со статусом `available`. Для сидячего места строка одна
 * на билет, поэтому ошибка не проявлялась. Стоячая зона — ОДНА строка на всю
 * вместимость, и «Манеж» на 800 человек отдавал в ответе `available_seats`
 * равное 0 или 1. Витрина на этом основании писала «Билетов нет» и держала
 * кнопку выключенной при 798 свободных местах. Нашлось живой проверкой
 * страницы события (headless Chrome), а не юнит-тестами.
 *
 * Второй случай — статус `sold` у стоячей зоны после первой продажи: это
 * статус ПОЗИЦИИ, а не признак «распродано целиком»
 * (`InventorySoldOnPaymentTest::test_a_paid_standing_zone_with_capacity_left_still_becomes_sold`).
 * Поэтому остаток обязан считаться независимо от того, `available` зона или
 * `sold`.
 */
final class EventSessionAvailabilityTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    /** @return array<string, mixed> */
    private function detail(int $inventoryItemId): array
    {
        $slug = DB::table('inventory_items')
            ->join('sessions', 'sessions.id', '=', 'inventory_items.session_id')
            ->join('events', 'events.id', '=', 'sessions.event_id')
            ->where('inventory_items.id', $inventoryItemId)
            ->value('events.slug');

        $response = $this->getJson('/api/v1/events/by-slug/' . $slug);
        $response->assertOk();

        return $response->json('data');
    }

    public function test_standing_zone_reports_tickets_not_warehouse_positions(): void
    {
        [, , , $itemId] = $this->seedSellableSeat(available: 5, priceAmount: 5_000_00);

        $data = $this->detail($itemId);

        self::assertSame(
            5,
            $data['sessions'][0]['available_seats'],
            'стоячая зона на 5 билетов — это 5 мест в ответе, а не одна позиция склада'
        );
    }

    public function test_sold_standing_zone_with_capacity_left_stays_on_sale(): void
    {
        [, , , $itemId] = $this->seedSellableSeat(available: 5, priceAmount: 5_000_00);

        // Так позицию помечает PaymentService после оплаты части билетов:
        // статус `sold`, но вместимость израсходована не полностью.
        DB::table('inventory_items')
            ->where('id', $itemId)
            ->update(['status' => 'sold', 'available_quantity' => 3]);

        $data = $this->detail($itemId);

        self::assertSame(
            3,
            $data['sessions'][0]['available_seats'],
            'статус sold у стоячей зоны не должен скрывать оставшиеся билеты'
        );
    }

    public function test_fully_sold_standing_zone_reports_zero(): void
    {
        [, , , $itemId] = $this->seedSellableSeat(available: 5, priceAmount: 5_000_00);

        DB::table('inventory_items')
            ->where('id', $itemId)
            ->update(['status' => 'sold_out', 'available_quantity' => 0]);

        $data = $this->detail($itemId);

        self::assertSame(0, $data['sessions'][0]['available_seats']);
    }
}
