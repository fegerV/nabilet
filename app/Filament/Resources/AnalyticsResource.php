<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnalyticsResource\Pages;
use Filament\Resources\Resource;
use Filament\Widgets;

class AnalyticsResource extends Resource
{
    protected static ?string $model = \App\Models\Booking::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    
    protected static ?string $navigationLabel = 'Аналитика';
    
    protected static ?int $navigationSort = 10;

    public static function getPages(): array
    {
        return [
            'index' => Pages\Dashboard::route('/'),
            'reports' => Pages\Reports::route('/reports'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
