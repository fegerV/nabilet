<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\EventSponsor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;

class EventSponsorResource extends Resource
{
    protected static ?string $model = EventSponsor::class;
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?string $navigationGroup = 'Events & Tickets';
    protected static ?string $label = 'Sponsor';
    protected static ?string $pluralLabel = 'Sponsors';
    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sponsor Information')
                    ->description('Details about the sponsor')
                    ->icon('heroicon-o-building-office')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('event_id')
                            ->relationship('event', 'title')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Event'),
                        
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Company Name'),
                        
                        Forms\Components\Select::make('tier')
                            ->options([
                                'platinum' => 'Platinum',
                                'gold' => 'Gold',
                                'silver' => 'Silver',
                                'bronze' => 'Bronze',
                                'standard' => 'Standard',
                            ])
                            ->default('standard')
                            ->required()
                            ->label('Sponsorship Tier'),
                        
                        Forms\Components\FileUpload::make('logo')
                            ->image()
                            ->directory('sponsors/logos')
                            ->maxSize(2048)
                            ->label('Company Logo'),
                        
                        Forms\Components\TextInput::make('website')
                            ->url()
                            ->maxLength(255)
                            ->label('Website URL'),
                        
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->label('Active'),
                        
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Sort Order'),
                    ]),
                
                Forms\Components\Section::make('Description')
                    ->icon('heroicon-o-document-text')
                    ->columns(1)
                    ->schema([
                        Forms\Components\RichEditor::make('description')
                            ->label('Description')
                            ->columnSpanFull(),
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
                
                ImageColumn::make('logo')
                    ->circular(false)
                    ->label('Logo'),
                
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Name'),
                
                BadgeColumn::make('tier')
                    ->colors([
                        'purple' => 'platinum',
                        'yellow' => 'gold',
                        'gray' => 'silver',
                        'orange' => 'bronze',
                        'blue' => 'standard',
                    ])
                    ->label('Tier'),
                
                TextColumn::make('event.title')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->label('Event'),
                
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                
                TextColumn::make('sort_order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->relationship('event', 'title')
                    ->label('Filter by Event'),
                
                SelectFilter::make('tier')
                    ->options([
                        'platinum' => 'Platinum',
                        'gold' => 'Gold',
                        'silver' => 'Silver',
                        'bronze' => 'Bronze',
                        'standard' => 'Standard',
                    ])
                    ->label('Filter by Tier'),
                
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Only'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ReplicateAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('tier', 'asc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\EventSponsorResource\Pages\ListEventSponsors::route('/'),
            'create' => \App\Filament\Resources\EventSponsorResource\Pages\CreateEventSponsor::route('/create'),
            'edit' => \App\Filament\Resources\EventSponsorResource\Pages\EditEventSponsor::route('/{record}/edit'),
        ];
    }
}
