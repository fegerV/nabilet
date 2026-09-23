<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Filament\Resources\AnalyticsResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Analytics\Services\AnalyticsReportService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Аналитика против реальной схемы.
 *
 * Панель аналитики читала `bookings` и `booking_seats` — таблицы архивной схемы,
 * которых в `nabilet_core_spec/migrations.sql` нет, — и три несуществующие колонки:
 * `analytics_events.event_type` (реально `event_name`), `users.name` (реально
 * `first_name`/`last_name`) и `events.name` (реально `title`). Класс
 * `\App\Models\Booking`, на который ссылался ресурс Filament, не существовал вовсе.
 *
 * Тесты ниже выполняют запросы к настоящей базе: если бы имена таблиц или колонок
 * остались выдуманными, они упали бы на `SQLSTATE[42P01]`, а не прошли молча.
 */
class AnalyticsReportTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PRICE = 5_000_000;

    private AnalyticsReportService $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = $this->app->make(AnalyticsReportService::class);
    }

    public function test_resource_model_is_a_class_that_exists(): void
    {
        // `\App\Models\Booking` проходил статическую проверку и падал на первом
        // запросе к getEloquentQuery().
        $this->assertTrue(
            class_exists(AnalyticsResource::getModel()),
            'Модель ресурса аналитики должна существовать: ' . AnalyticsResource::getModel()
        );
    }

    public function test_metrics_count_revenue_from_paid_orders_only(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);

        $this->seedOrder($seed, 'paid', 2);
        $this->seedOrder($seed, 'cancelled', 1);
        $this->seedOrder($seed, 'pending', 1);

        $metrics = $this->report->metrics(now()->subDay());

        $this->assertSame(2 * self::PRICE, $metrics['totalSales'], 'Выручка — только оплаченные заказы');
        $this->assertSame(2 * self::PRICE, $metrics['revenue']);
        $this->assertSame(3, $metrics['totalOrders'], 'Всего заказов — включая отменённые и ожидающие');

        // Считаются строки tickets за период, без фильтра по статусу заказа: в
        // реальном потоке билеты выпускаются только после оплаты, поэтому фильтр
        // был бы лишним — но и отменённый заказ здесь билеты имеет, и метрика их
        // показывает. 2 + 1 + 1 = 4.
        $this->assertSame(4, $metrics['totalTicketsSold']);
    }

    public function test_metrics_ignore_orders_outside_the_period(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);

        $this->seedOrder($seed, 'paid', 1);
        $this->seedOrder($seed, 'paid', 1, createdAt: now()->subDays(90)->toDateTimeString());

        $metrics = $this->report->metrics(now()->subDays(30));

        $this->assertSame(1, $metrics['totalOrders']);
        $this->assertSame(self::PRICE, $metrics['totalSales']);
    }

    public function test_top_events_does_not_multiply_revenue_by_ticket_count(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE, 'Событие с фан-аутом');

        // Один заказ, три билета на одно событие. Наивный JOIN даёт три строки, и
        // SUM(orders.total_amount) утроил бы выручку.
        $this->seedOrder($seed, 'paid', 3);

        $top = $this->report->topEvents(now()->subDay());

        $this->assertCount(1, $top);
        $this->assertSame('Событие с фан-аутом', $top[0]->name, 'Колонка events.title, не events.name');
        $this->assertSame(1, (int) $top[0]->orders, 'Три билета — это один заказ');
        $this->assertSame(3 * self::PRICE, (int) $top[0]->revenue, 'Выручка заказа не должна умножаться на число билетов');
    }

    public function test_top_events_excludes_unpaid_orders(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE, 'Неоплаченное');
        $this->seedOrder($seed, 'pending', 1);

        $this->assertCount(0, $this->report->topEvents(now()->subDay()));
    }

    public function test_sales_by_day_reports_revenue_and_order_counts(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);

        $this->seedOrder($seed, 'paid', 1);
        $this->seedOrder($seed, 'pending', 1);

        $days = $this->report->salesByDay(now()->subDay());

        $this->assertCount(1, $days);
        $this->assertSame(now()->toDateString(), $days[0]['date'], 'CAST(created_at AS date) работает и на PostgreSQL');
        $this->assertSame(2, $days[0]['orders']);
        $this->assertSame((float) self::PRICE, $days[0]['revenue']);
    }

    public function test_orders_for_export_keeps_guest_orders(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);

        // user_id = NULL — гостевая покупка. INNER JOIN молча выбросил бы заказ.
        $this->seedOrder($seed, 'paid', 1, userId: null);

        $rows = $this->report->ordersForExport(now()->subDay());

        $this->assertCount(1, $rows, 'Гостевой заказ обязан попадать в экспорт (LEFT JOIN)');
        $this->assertNull($rows[0]->first_name);
    }

    public function test_orders_for_export_includes_buyer_name_from_first_and_last_name(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);
        $userId = $this->seedUser();

        $this->seedOrder($seed, 'paid', 1, userId: $userId);

        $rows = $this->report->ordersForExport(now()->subDay());

        $this->assertSame('Аскар', $rows[0]->first_name, 'Колонка users.first_name, не users.name');
        $this->assertSame('Нурланов', $rows[0]->last_name);
    }

    public function test_unique_visitors_counts_people_not_sessions(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);
        $sessionId = $seed[2];

        // Три просмотра: два от одного анонима, один от вошедшего пользователя.
        // Старый запрос считал DISTINCT session_id, то есть число спектаклей.
        $this->seedAnalyticsEvent('page_view', 'anon-1', null, $sessionId);
        $this->seedAnalyticsEvent('page_view', 'anon-1', null, $sessionId);
        $this->seedAnalyticsEvent('page_view', 'anon-2', null, $sessionId);
        $this->seedAnalyticsEvent('seat_selected', 'anon-3', null, $sessionId);

        $this->assertSame(2, $this->report->uniqueVisitors(now()->subDay()));
    }

    public function test_order_statuses_are_grouped(): void
    {
        $seed = $this->seedSellableSeat(10, self::PRICE);

        $this->seedOrder($seed, 'paid', 1);
        $this->seedOrder($seed, 'paid', 1);
        $this->seedOrder($seed, 'cancelled', 1);

        $statuses = $this->report->orderStatuses(now()->subDay())
            ->pluck('count', 'status')
            ->all();

        $this->assertSame(2, (int) $statuses['paid']);
        $this->assertSame(1, (int) $statuses['cancelled']);
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $seed
     */
    private function seedOrder(
        array $seed,
        string $status,
        int $ticketCount,
        ?int $userId = null,
        ?string $createdAt = null,
    ): int {
        [$organizationId, $eventId, $sessionId, $inventoryItemId] = $seed;

        $createdAt ??= now()->toDateTimeString();
        $total = $ticketCount * self::PRICE;

        $orderId = (int) DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'order_number' => 'NB-' . Str::random(10),
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'subtotal_amount' => $total,
            'discount_amount' => 0,
            'fee_amount' => 0,
            'total_amount' => $total,
            'currency' => 'RUB',
            'status' => $status,
            'payment_status' => $status === 'paid' ? 'succeeded' : 'pending',
            'customer_email' => 'buyer@example.test',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => $ticketCount,
            'unit_price' => self::PRICE,
            'discount_amount' => 0,
            'fee_amount' => 0,
            'total_amount' => $total,
            'event_title_snapshot' => 'Событие',
            'created_at' => $createdAt,
        ]);

        for ($i = 1; $i <= $ticketCount; $i++) {
            DB::table('tickets')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'ticket_number' => 'T-' . Str::random(12),
                'ticket_index' => $i,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'event_id' => $eventId,
                'session_id' => $sessionId,
                'inventory_item_id' => $inventoryItemId,
                'status' => 'issued',
                'qr_version' => 1,
                'qr_token_hash' => hash('sha256', Str::random(32)),
                'issued_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $orderId;
    }

    private function seedUser(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'askar-' . Str::random(6) . '@example.test',
            'first_name' => 'Аскар',
            'last_name' => 'Нурланов',
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'Asia/Almaty',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedAnalyticsEvent(string $eventName, ?string $anonymousId, ?int $userId, ?int $sessionId): void
    {
        $now = now()->toDateTimeString();

        DB::table('analytics_events')->insert([
            'user_id' => $userId,
            'anonymous_id' => $anonymousId,
            'session_id' => $sessionId,
            'event_name' => $eventName,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }
}
