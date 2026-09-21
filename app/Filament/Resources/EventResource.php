<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Event';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('category_id'),
            Forms\Components\TextInput::make('title'),
            Forms\Components\TextInput::make('slug'),
            Forms\Components\RichEditor::make('short_description'),
            Forms\Components\RichEditor::make('description'),
            Forms\Components\TextInput::make('poster')->url(),
            Forms\Components\TextInput::make('cover')->url(),
            Forms\Components\TextInput::make('age_limit'),
            Forms\Components\TextInput::make('duration_minutes'),
            Forms\Components\Select::make('status'),
            Forms\Components\DateTimePicker::make('published_at'),
            Forms\Components\TextInput::make('seo_title'),
            Forms\Components\TextInput::make('seo_description'),
            Forms\Components\TextInput::make('canonical_url')->url(),
            Forms\Components\TextInput::make('robots'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => EventResource\Pages\ListEvents::route('/'),
            'create' => EventResource\Pages\CreateEvent::route('/create'),
            'edit' => EventResource\Pages\EditEvent::route('/{record}/edit'),
        ];
    }
}