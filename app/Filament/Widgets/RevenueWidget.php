<?php
namespace App\Filament\Widgets;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
class RevenueWidget extends BaseWidget { protected function getStats(): array { $revenue = DB::table('payments')->where('status','succeeded')->sum('amount'); $orderCount = DB::table('orders')->count(); $ticketSold = DB::table('tickets')->whereIn('status',['issued','used'])->count(); return [Stat::make('Выручка', number_format($revenue/100,2).' ₽')->description('Общая выручка')->color('success'), Stat::make('Активные заказы', DB::table('orders')->whereIn('status',['pending','paid'])->count())->description($orderCount.' всего')->color('warning'), Stat::make('Проданные билеты', $ticketSold)->description('Всего продано')->color('info'),]; } }