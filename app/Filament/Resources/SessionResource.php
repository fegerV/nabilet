<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Session;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SessionResource extends Resource
{
    protected static ?string $model = Session::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Session';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('event_id'),
            Forms\Components\TextInput::make('venue_id'),
            Forms\Components\TextInput::make('hall_id'),
            Forms\Components\TextInput::make('schema_version_id'),
            Forms\Components\DateTimePicker::make('starts_at'),
            Forms\Components\DateTimePicker::make('ends_at'),
            Forms\Components\DateTimePicker::make('sales_start_at'),
            Forms\Components\DateTimePicker::make('sales_end_at'),
            Forms\Components\Select::make('timezone'),
            Forms\Components\Select::make('status'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => SessionResource\Pages\ListSessions::route('/'),
            'create' => SessionResource\Pages\CreateSession::route('/create'),
            'edit' => SessionResource\Pages\EditSession::route('/{record}/edit'),
        ];
    }
}
