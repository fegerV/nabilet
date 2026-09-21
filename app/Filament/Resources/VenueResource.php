<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Venue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Venue';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name'),
            Forms\Components\TextInput::make('slug'),
            Forms\Components\RichEditor::make('description'),
            Forms\Components\TextInput::make('country'),
            Forms\Components\TextInput::make('region'),
            Forms\Components\TextInput::make('city'),
            Forms\Components\TextInput::make('address'),
            Forms\Components\TextInput::make('latitude'),
            Forms\Components\TextInput::make('longitude'),
            Forms\Components\Select::make('status'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => VenueResource\Pages\ListVenues::route('/'),
            'create' => VenueResource\Pages\CreateVenue::route('/create'),
            'edit' => VenueResource\Pages\EditVenue::route('/{record}/edit'),
        ];
    }
}
