<?php
namespace App\Filament\Widgets;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
class ScanFeedWidget extends Widget { protected static ?string $heading = 'Лента сканирований'; protected int|string|array $columnSpan = 'full'; public function getView(): string { return 'filament::widgets.table-widget'; } protected function getTableData(): array { return DB::table('ticket_scans')->join('tickets','ticket_scans.ticket_id','=','tickets.id')->join('sessions','tickets.session_id','=','sessions.id')->join('events','sessions.event_id','=','events.id')->select('tickets.qr_data','events.title as event',DB::raw('ticket_scans.created_at as scanned_at'),'ticket_scans.status')->orderBy('ticket_scans.created_at','desc')->limit(10)->get()->toArray(); } }