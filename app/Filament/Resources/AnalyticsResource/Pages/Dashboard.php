<?php

namespace App\Filament\Resources\AnalyticsResource\Pages;

use App\Filament\Resources\AnalyticsResource;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    
    protected static string $view = 'filament.pages.analytics-dashboard';
    
    protected static ?string $title = 'Панель аналитики';

    public ?string $period = '30';
    
    public array $metrics = [];
    
    public array $salesData = [];
    
    public array $topEvents = [];

    public function mount()
    {
        $this->loadMetrics();
    }

    public function loadMetrics()
    {
        $startDate = Carbon::now()->subDays($this->period);

        // Общие метрики
        $this->metrics = [
            'totalSales' => DB::table('bookings')
                ->where('created_at', '>=', $startDate)
                ->where('status', 'confirmed')
                ->sum('total_price'),
            
            'totalOrders' => DB::table('bookings')
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            'totalTicketsSold' => DB::table('booking_seats')
                ->join('bookings', 'booking_seats.booking_id', '=', 'bookings.id')
                ->where('bookings.created_at', '>=', $startDate)
                ->count(),
            
            'revenue' => DB::table('bookings')
                ->where('created_at', '>=', $startDate)
                ->where('status', 'confirmed')
                ->sum('total_price'),
        ];

        // Продажи по дням для графика
        $this->salesData = DB::table('bookings')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(total_price) as revenue')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($item) => [
                'date' => $item->date,
                'orders' => (int) $item->orders,
                'revenue' => (float) $item->revenue,
            ])
            ->toArray();

        // Популярные события
        $this->topEvents = DB::table('events')
            ->leftJoin('bookings', 'events.id', '=', 'bookings.event_id')
            ->where('bookings.created_at', '>=', $startDate)
            ->select(
                'events.name',
                DB::raw('COUNT(bookings.id) as orders'),
                DB::raw('COALESCE(SUM(bookings.total_price), 0) as revenue')
            )
            ->groupBy('events.id', 'events.name')
            ->orderBy('revenue', 'desc')
            ->limit(5)
            ->get()
            ->toArray();
    }

    public function setPeriod($days)
    {
        $this->period = $days;
        $this->loadMetrics();
    }

    public static function getResource(): string
    {
        return AnalyticsResource::class;
    }
}
