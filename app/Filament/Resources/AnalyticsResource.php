<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnalyticsResource\Pages;
use Filament\Resources\Resource;
use Filament\Widgets;

class AnalyticsResource extends Resource
{
    // Аналитика — это дашборд с запросами к реальной схеме, а не CRUD-ресурс.
    // Модель указывает на реальную таблицу аналитических событий, чтобы
    // getEloquentQuery() (и тесты) находили существующий класс.
    protected static ?string $model = \Nabilet\Modules\Analytics\Models\AnalyticsEvent::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    
    protected static ?string $navigationLabel = 'Аналитика';
    
    protected static ?int $navigationSort = 10;

    public static function getPages(): array
    {
        return [
            'index' => Pages\Dashboard::route('/'),
            // Страница Pages\Reports отсутствует (нет ни класса, ни шаблона) —
            // ссылка на неё приводила к падению artisan целиком.
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
