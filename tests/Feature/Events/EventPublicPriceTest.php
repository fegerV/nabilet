<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «от N ₽» на витрине.
 *
 * ПОЧЕМУ ЭТОТ ТЕСТ СУЩЕСТВУЕТ
 *
 * Публичный список событий (`GET /api/v1/events`) не отдавал цену вовсе: поля
 * в `EventResource` не было, и витрина подставляла `?? 0` — каждая карточка
 * афиши показывала «от 0 ₽». Обнаружено ЖИВОЙ проверкой витрины (headless
 * Chrome, мобильный кадр 390×844), а не юнит-тестами: они проверяли поля,
 * которых в ответе и не должно было быть по их логике. Тест закрепляет оба
 * конца контракта: список и карточку по slug.
 */
class EventPublicPriceTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    public function test_catalog_list_returns_min_price_of_upcoming_sessions(): void
    {
        [, , , $itemId] = $this->seedSellableSeat(available: 3, priceAmount: 4_500_00);

        // Второй, более дешёвый сеанс того же события: «от» обязан взять
        // минимум, а не цену первой попавшейся позиции.
        [$eventId] = [$this->eventIdOf($itemId)];
        $this->addSessionWithPrice($eventId, 1_900_00);

        $prices = $this->priceById();

        self::assertSame(190_000, $prices[$eventId]);
    }

    public function test_by_slug_returns_min_price_for_the_single_event(): void
    {
        [, , , $inventoryId] = $this->seedSellableSeat(available: 2, priceAmount: 3_000_00);
        $slug = $this->slugOf($this->eventIdOf($inventoryId));

        $this->getJson("/api/v1/events/by-slug/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from_minor', 300_000)
            ->assertJsonPath('data.priceFromMinor', 300_000);
    }

    /**
     * Прошедший сеанс цены не задаёт.
     *
     * «от 3 000 ₽» — цена ближайшей покупки. Если бы в минимум попал прошедший
     * сеанс с ценой поменьше, афиша обещала бы вход по ней на концерт, который
     * уже прошёл.
     */
    public function test_past_session_price_is_not_used(): void
    {
        [, , , $itemId] = $this->seedSellableSeat(available: 1, priceAmount: 7_700_00);
        $slug = $this->slugOf($this->eventIdOf($itemId));

        // Дешёвая позиция — но в прошедшем сеансе: в цене афиши её быть не должно.
        $this->addSessionWithPrice($this->eventIdOf($itemId), 1_00, inDays: -10);

        $this->getJson("/api/v1/events/by-slug/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.price_from_minor', 770_000);
    }

    /** @return array<int, int|null> id события → минимальная цена */
    private function priceById(): array
    {
        $response = $this->getJson('/api/v1/events?status=published&per_page=100');

        $response->assertOk();

        return collect($response->json('data'))
            ->mapWithKeys(fn (array $e): array => [$e['id'] => $e['price_from_minor'] ?? null])
            ->all();
    }

    /**
     * Ещё один сеанс события со своей ценой.
     *
     * Своя СТОЯЧАЯ ЗОНА, а не общая с первым сеансом: цена висит на позиции
     * склада (`inventory_items`), а позиция обязана указывать ровно на один
     * «якорь» — `ck_inventory_target` (место XOR зона). Новая зона на той же
     * версии схемы зала дешевле, чем повторное использование чужой.
     */
    private function addSessionWithPrice(int $eventId, int $priceAmount, ?int $inDays = 7): void
    {
        $now = now()->toDateTimeString();
        $template = DB::table('sessions')->where('event_id', $eventId)->orderBy('id')->firstOrFail();

        $zoneId = $this->seedStandingZone((int) $template->schema_version_id, $priceAmount);

        $sessionId = (int) DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => $eventId,
            'venue_id' => $template->venue_id,
            'hall_id' => $template->hall_id,
            'schema_version_id' => $template->schema_version_id,
            'starts_at' => now()->addDays($inDays ?? 7)->toDateTimeString(),
            'timezone' => 'Asia/Almaty',
            'status' => 'on_sale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('inventory_items')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'session_id' => $sessionId,
            'type' => 'standing',
            'standing_zone_id' => $zoneId,
            'price_amount' => $priceAmount,
            'currency' => 'RUB',
            'capacity' => 5,
            'available_quantity' => 5,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function slugOf(int $eventId): string
    {
        return (string) DB::table('events')->where('id', $eventId)->value('slug');
    }

    private function eventIdOf(int $inventoryItemId): int
    {
        return (int) DB::table('inventory_items')
            ->join('sessions', 'sessions.id', '=', 'inventory_items.session_id')
            ->where('inventory_items.id', $inventoryItemId)
            ->value('sessions.event_id');
    }
}
