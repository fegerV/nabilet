<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Главная панель аналитики
     */
    public function dashboard(Request $request)
    {
        $period = $request->get('period', '30'); // дней
        $startDate = Carbon::now()->subDays($period);

        // Общие метрики
        $totalSales = DB::table('bookings')
            ->where('created_at', '>=', $startDate)
            ->sum('total_price');

        $totalOrders = DB::table('bookings')
            ->where('created_at', '>=', $startDate)
            ->count();

        $totalTicketsSold = DB::table('booking_seats')
            ->join('bookings', 'booking_seats.booking_id', '=', 'bookings.id')
            ->where('bookings.created_at', '>=', $startDate)
            ->count();

        $uniqueVisitors = DB::table('analytics_events')
            ->where('event_type', 'page_view')
            ->where('created_at', '>=', $startDate)
            ->distinct('session_id')
            ->count('session_id');

        // Продажи по дням
        $salesByDay = DB::table('bookings')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_price) as total'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Популярные события
        $topEvents = DB::table('events')
            ->leftJoin('bookings', 'events.id', '=', 'bookings.event_id')
            ->where('bookings.created_at', '>=', $startDate)
            ->select('events.name', DB::raw('COUNT(bookings.id) as orders'), DB::raw('SUM(bookings.total_price) as revenue'))
            ->groupBy('events.id', 'events.name')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        // Статусы бронирований
        $bookingStatuses = DB::table('bookings')
            ->where('created_at', '>=', $startDate)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'metrics' => [
                'total_sales' => $totalSales,
                'total_orders' => $totalOrders,
                'total_tickets_sold' => $totalTicketsSold,
                'unique_visitors' => $uniqueVisitors,
                'average_order_value' => $totalOrders > 0 ? $totalSales / $totalOrders : 0,
            ],
            'sales_by_day' => $salesByDay,
            'top_events' => $topEvents,
            'booking_statuses' => $bookingStatuses,
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => Carbon::now()->toDateString(),
                'days' => $period,
            ],
        ]);
    }

    /**
     * Экспорт аналитики в CSV
     */
    public function exportCsv(Request $request)
    {
        $period = $request->get('period', '30');
        $startDate = Carbon::now()->subDays($period);

        $data = DB::table('bookings')
            ->join('users', 'bookings.user_id', '=', 'users.id')
            ->join('events', 'bookings.event_id', '=', 'events.id')
            ->where('bookings.created_at', '>=', $startDate)
            ->select(
                'bookings.id',
                'bookings.created_at',
                'users.name as customer_name',
                'users.email as customer_email',
                'events.name as event_name',
                'bookings.total_price',
                'bookings.status'
            )
            ->orderBy('bookings.created_at', 'desc')
            ->get();

        $csvData = "ID,Дата,Клиент,Email,Событие,Сумма,Статус\n";
        
        foreach ($data as $row) {
            $csvData .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $row->id,
                $row->created_at,
                $this->escapeCsv($row->customer_name),
                $row->customer_email,
                $this->escapeCsv($row->event_name),
                $row->total_price,
                $row->status
            );
        }

        return response($csvData, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="analytics_export_' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Экспорт аналитики в Excel (требует maatwebsite/excel)
     */
    public function exportExcel(Request $request)
    {
        // Реализация через пакет maatwebsite/excel
        // return Excel::download(new AnalyticsExport, 'analytics.xlsx');
        
        return response()->json([
            'message' => 'Excel export requires maatwebsite/excel package',
            'fallback' => 'Use CSV export instead',
        ]);
    }

    private function escapeCsv($value)
    {
        if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
