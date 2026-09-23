<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SystemResource\Pages;
use Filament\Resources\Resource;

class SystemResource extends Resource
{
    protected static ?string $model = null;
    
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    
    protected static ?string $navigationLabel = 'Система';
    
    protected static ?int $navigationSort = 12;

    public static function getPages(): array
    {
        return [
            'index' => Pages\SystemStatus::route('/'),
            // Страница Pages\SystemLogs отсутствует (нет ни класса, ни шаблона) —
            // ссылка на неё приводила к падению artisan целиком.
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
