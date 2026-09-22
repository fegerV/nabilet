<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\EventDate;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Calendar widget showing upcoming event dates.
 */
class EventCalendarWidget extends Widget
{
    protected static string $view = 'filament.widgets.event-calendar-widget';
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?int $sort = 1;

    public function getUpcomingDates(): array
    {
        return EventDate::with('event')
            ->where('start_at', '>=', now())
            ->where('status', 'scheduled')
            ->orderBy('start_at', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($date) {
                return [
                    'id' => $date->id,
                    'event_title' => $date->event?->title ?? 'Unknown Event',
                    'name' => $date->name,
                    'start_at' => $date->start_at,
                    'end_at' => $date->end_at,
                    'status' => $date->status,
                    'is_sold_out' => $date->is_sold_out,
                ];
            })
            ->toArray();
    }

    public function getThisMonthEvents(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        return EventDate::with('event')
            ->whereBetween('start_at', [$startOfMonth, $endOfMonth])
            ->where('status', 'scheduled')
            ->orderBy('start_at', 'asc')
            ->get()
            ->groupBy(function ($date) {
                return $date->start_at->format('Y-m-d');
            })
            ->map(function ($dates) {
                return $dates->map(function ($date) {
                    return [
                        'id' => $date->id,
                        'event_title' => $date->event?->title ?? 'Unknown Event',
                        'name' => $date->name,
                        'start_at' => $date->start_at,
                        'is_sold_out' => $date->is_sold_out,
                    ];
                });
            })
            ->toArray();
    }
}
