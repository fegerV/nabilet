<?php
namespace App\Filament\Widgets;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
class RevenueWidget extends BaseWidget { protected function getStats(): array { $revenue = DB::table('payments')->where('status','completed')->whereNull('deleted_at')->sum('amount_minor'); $orderCount = DB::table('orders')->whereNull('deleted_at')->count(); $ticketSold = DB::table('tickets')->whereIn('status',['issued','checked_in'])->whereNull('deleted_at')->count(); return [Stat::make('Выручка', number_format($revenue/100,2).' ₽')->description('Общая выручка')->color('success'), Stat::make('Активные заказы', DB::table('orders')->whereIn('status',['pending','confirmed'])->whereNull('deleted_at')->count())->description($orderCount.' всего')->color('warning'), Stat::make('Проданные билеты', $ticketSold)->description('Всего продано')->color('info'),]; } }