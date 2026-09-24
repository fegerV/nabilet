<?php
namespace App\Filament\Widgets;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Лента последних сканирований билетов.
 * Рендерится через собственный Blade (filament.widgets.scan-feed-widget).
 */
class ScanFeedWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.scan-feed-widget';

    protected static ?string $heading = 'Лента сканирований';

    protected int | string | array $columnSpan = 'full';

    public function getScans(): array
    {
        return DB::table('ticket_scans')
            ->join('tickets', 'ticket_scans.ticket_id', '=', 'tickets.id')
            ->join('sessions', 'tickets.session_id', '=', 'sessions.id')
            ->join('events', 'sessions.event_id', '=', 'events.id')
            ->select(
                'tickets.qr_token_hash',
                'events.title as event',
                DB::raw('ticket_scans.created_at as scanned_at'),
                'ticket_scans.result'
            )
            ->orderBy('ticket_scans.created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }
}