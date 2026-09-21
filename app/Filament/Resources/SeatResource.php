<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Seat;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SeatResource extends Resource
{
    protected static ?string $model = Seat::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Seat';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('row_id'),
            Forms\Components\TextInput::make('number'),
            Forms\Components\TextInput::make('label'),
            Forms\Components\TextInput::make('x')->numeric(),
            Forms\Components\TextInput::make('y')->numeric(),
            Forms\Components\TextInput::make('width')->numeric(),
            Forms\Components\TextInput::make('height')->numeric(),
            Forms\Components\TextInput::make('rotation')->numeric(),
            Forms\Components\Select::make('type'),
            Forms\Components\Select::make('status'),
            Forms\Components\RichEditor::make('metadata_json'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => SeatResource\Pages\ListSeats::route('/'),
            'create' => SeatResource\Pages\CreateSeat::route('/create'),
            'edit' => SeatResource\Pages\EditSeat::route('/{record}/edit'),
        ];
    }
}
