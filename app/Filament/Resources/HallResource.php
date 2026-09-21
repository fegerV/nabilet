<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Hall;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HallResource extends Resource
{
    protected static ?string $model = Hall::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Hall';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('venue_id'),
            Forms\Components\TextInput::make('name'),
            Forms\Components\RichEditor::make('description'),
            Forms\Components\TextInput::make('capacity')->numeric(),
            Forms\Components\TextInput::make('width')->numeric(),
            Forms\Components\TextInput::make('height')->numeric(),
            Forms\Components\Select::make('status'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => HallResource\Pages\ListHalls::route('/'),
            'create' => HallResource\Pages\CreateHall::route('/create'),
            'edit' => HallResource\Pages\EditHall::route('/{record}/edit'),
        ];
    }
}
