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
    protected static ?string $navigationIcon = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Venue Management';
    protected static ?string $label = 'Hall';
    protected static ?string $pluralLabel = 'Halls';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->description('Core details about the hall')
                    ->icon('heroicon-o-information-circle')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('venue_id')
                            ->relationship('venue', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->label('Venue'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Hall Name'),
                        Forms\Components\RichEditor::make('description')
                            ->columnSpanFull()
                            ->label('Description'),
                        Forms\Components\TextInput::make('capacity')
                            ->numeric()
                            ->minValue(1)
                            ->label('Capacity (persons)'),
                    ]),
                
                Forms\Components\Section::make('Dimensions')
                    ->description('Physical dimensions of the hall in meters')
                    ->icon('heroicon-o-ruler')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('width')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('m')
                            ->label('Width'),
                        Forms\Components\TextInput::make('height')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('m')
                            ->label('Length'),
                    ]),
                
                Forms\Components\Section::make('Status')
                    ->icon('heroicon-o-check-circle')
                    ->columns(1)
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'maintenance' => 'Maintenance',
                            ])
                            ->default('active')
                            ->required()
                            ->label('Status'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('venue.name')
                    ->sortable()
                    ->searchable()
                    ->label('Venue'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Hall Name'),
                Tables\Columns\TextColumn::make('capacity')
                    ->numeric()
                    ->sortable()
                    ->label('Capacity'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                        'warning' => 'maintenance',
                    ])
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Created At'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                    ]),
                Tables\Filters\SelectFilter::make('venue')
                    ->relationship('venue', 'name')
                    ->label('Filter by Venue'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('editSchema')
                    ->label('Edit Schema')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn (Hall $record): string => route('filament.admin.halls.schema.edit', ['record' => $record]))
                    ->color('success')
                    ->visible(fn (Hall $record): bool => $record->status === 'active'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array 
    { 
        return []; 
    }
    
    public static function getPages(): array
    {
        return [
            'index' => HallResource\Pages\ListHalls::route('/'),
            'create' => HallResource\Pages\CreateHall::route('/create'),
            'edit' => HallResource\Pages\EditHall::route('/{record}/edit'),
        ];
    }
    
    public static function canViewAny($record): bool
    {
        return true;
    }
}
