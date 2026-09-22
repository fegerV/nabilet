<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BackupResource\Pages;
use Filament\Resources\Resource;

class BackupResource extends Resource
{
    protected static ?string $model = null;
    
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';
    
    protected static ?string $navigationLabel = 'Бэкапы';
    
    protected static ?int $navigationSort = 11;

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBackups::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }
}
