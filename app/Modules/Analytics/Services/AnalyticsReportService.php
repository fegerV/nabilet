<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика против реальной схемы.
 *
 * Панель когда-то читала `bookings`/`booking_seats` — таблицы архивной схемы,
 * которых в `nabilet_core_spec/migrations.sql` нет, — и колонки
 * `analytics_events.event_type` (реально `event_name`), `users.name` (реально
 * `first_name`/`last_name`) и `events.name` (реально `title`).
 *
 * Каждый запрос ниже явно перечисляет колонки реальной схемы; тесты
 * `tests/Feature/Analytics/AnalyticsReportTest.php` кладут данные в настоящую
 * базу и проверяют результаты — если имя таблицы или колонки выдумано,
 * запрос упадёт на SQLSTATE[42P01]/[42703].
 */
class AnalyticsReportService
{
    /** Интервал отчёта по умолчанию, дней. */
    public const DEFAULT_DAYS = 30;

    /**
     * Общие метрики за период (все заказы, оплаченные отдельно).
     *
     * @return array{
     *     totalSales: int,
     *     revenue: int,
     *     totalOrders: int,
     *     totalTicketsSold: int,
     *     uniqueVisitors: int,
     * }
     */
    public function metrics(string|\DateTimeInterface $since): array
    {
        $since = $this->moment($since);

        $totalOrders = (int) DB::table('orders')
            ->where('created_at', '>=', $since)
            ->count();

        $totalSales = (int) DB::table('orders')
            ->where('created_at', '>=', $since)
            ->where('status', 'paid')
            ->sum('total_amount');

        $totalTicketsSold = (int) DB::table('tickets')
            ->where('created_at', '>=', $since)
            ->count();

        $uniqueVisitors = (int) DB::table('analytics_events')
            ->where('event_name', 'page_view')
            ->where('occurred_at', '>=', $since)
            ->distinct()
            ->count(DB::raw('COALESCE(user_id::text, anonymous_id)'));

        return [
            'totalSales' => $totalSales,
            'revenue' => $totalSales,
            'totalOrders' => $totalOrders,
            'totalTicketsSold' => $totalTicketsSold,
            'uniqueVisitors' => $uniqueVisitors,
        ];
    }

    /**
     * Топ событий по выручке (только оплаченные заказы).
     *
     * @return Collection<int, object{name: string, orders: int, revenue: int}>
     */
    public function topEvents(string|\DateTimeInterface $since): Collection
    {
        $since = $this->moment($since);

        // Считаем заказы из orders, а не из tickets: три билета в одном заказе
        // не должны утраивать выручку. DISTINCT ON (o.id) отбрасывает дубликаты
        // строк от join с order_items.
        return DB::table('orders as o')
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('inventory_items as ii', 'ii.id', '=', 'oi.inventory_item_id')
            ->join('sessions as s', 's.id', '=', 'ii.session_id')
            ->join('events as e', 'e.id', '=', 's.event_id')
            ->where('o.created_at', '>=', $since)
            ->where('o.status', 'paid')
            ->select(
                'e.title as name',
                DB::raw('COUNT(DISTINCT o.id) as orders'),
                DB::raw('SUM(o.total_amount) as revenue')
            )
            ->groupBy('e.id', 'e.title')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();
    }

    /**
     * Продажи по дням: дата, число заказов, выручка.
     *
     * @return array<int, array{date: string, orders: int, revenue: float}>
     */
    public function salesByDay(string|\DateTimeInterface $since): array
    {
        $since = $this->moment($since);

        $rows = DB::table('orders')
            ->where('created_at', '>=', $since)
            ->selectRaw('CAST(created_at AS date) as date')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END) as revenue', ['paid'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $rows->map(fn ($r) => [
            'date' => (string) $r->date,
            'orders' => (int) $r->orders,
            'revenue' => (float) $r->revenue,
        ])->all();
    }

    /**
     * Заказы для экспорта. LEFT JOIN с users: гостевые заказы (user_id NULL)
     * обязаны попадать в выгрузку.
     *
     * @return Collection<int, object>
     */
    public function ordersForExport(string|\DateTimeInterface $since): Collection
    {
        $since = $this->moment($since);

        return DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.created_at', '>=', $since)
            ->select(
                'o.id',
                'o.order_number',
                'o.created_at',
                'u.first_name',
                'u.last_name',
                'u.email as customer_email',
                'o.customer_email as guest_email',
                'o.status',
                'o.total_amount',
                'o.currency'
            )
            ->orderByDesc('o.created_at')
            ->get();
    }

    /**
     * Уникальные посетители за период.
     */
    public function uniqueVisitors(string|\DateTimeInterface $since): int
    {
        return (int) $this->metrics($since)['uniqueVisitors'];
    }

    /**
     * Статусы заказов с количеством.
     *
     * @return Collection<int, object{status: string, count: int}>
     */
    public function orderStatuses(string|\DateTimeInterface $since): Collection
    {
        $since = $this->moment($since);

        return DB::table('orders')
            ->where('created_at', '>=', $since)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get();
    }

    private function moment(string|\DateTimeInterface $since): string
    {
        if ($since instanceof \DateTimeInterface) {
            return $since->format('Y-m-d H:i:s');
        }

        return $since;
    }
}