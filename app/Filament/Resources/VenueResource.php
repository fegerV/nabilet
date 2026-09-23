<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Venue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;

class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationGroup = 'Venue Management';
    protected static ?string $label = 'Venue';
    protected static ?string $pluralLabel = 'Venues';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->description('Core details about the venue')
                    ->icon('heroicon-o-information-circle')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Venue Name'),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->label('Slug (URL-friendly name)'),
                        Forms\Components\Select::make('organization_id')
                            ->relationship('organization', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->label('Organization'),
                    ]),
                
                Forms\Components\Section::make('Description')
                    ->icon('heroicon-o-document-text')
                    ->columns(1)
                    ->schema([
                        Forms\Components\RichEditor::make('description')
                            ->label('Full Description')
                            ->columnSpanFull(),
                    ]),
                
                Forms\Components\Section::make('Location Details')
                    ->description('Geographic and address information')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('country')
                            ->maxLength(100)
                            ->label('Country'),
                        Forms\Components\TextInput::make('region')
                            ->maxLength(150)
                            ->label('Region/State'),
                        Forms\Components\TextInput::make('city')
                            ->maxLength(150)
                            ->label('City'),
                        Forms\Components\TextInput::make('address')
                            ->maxLength(500)
                            ->label('Street Address'),
                        Forms\Components\TextInput::make('latitude')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->suffix('°')
                            ->label('Latitude'),
                        Forms\Components\TextInput::make('longitude')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->suffix('°')
                            ->label('Longitude'),
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
                TextColumn::make('id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('organization.name')
                    ->sortable()
                    ->searchable()
                    ->label('Organization'),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Venue Name'),
                TextColumn::make('city')
                    ->searchable()
                    ->sortable()
                    ->label('City'),
                TextColumn::make('country')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->label('Country'),
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                        'warning' => 'maintenance',
                    ])
                    ->label('Status'),
                TextColumn::make('created_at')
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
                Tables\Filters\SelectFilter::make('organization')
                    ->relationship('organization', 'name')
                    ->label('Filter by Organization'),
                Tables\Filters\SelectFilter::make('city')
                    ->label('Filter by City'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
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
            'index' => VenueResource\Pages\ListVenues::route('/'),
            'create' => VenueResource\Pages\CreateVenue::route('/create'),
            'edit' => VenueResource\Pages\EditVenue::route('/{record}/edit'),
        ];
    }
}
