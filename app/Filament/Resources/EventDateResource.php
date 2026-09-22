<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\EventDate;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions;

class EventDateResource extends Resource
{
    protected static ?string $model = EventDate::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Events & Tickets';
    protected static ?string $label = 'Event Date';
    protected static ?string $pluralLabel = 'Event Dates';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Event Date Details')
                    ->description('Specific date and time for this event session')
                    ->icon('heroicon-o-calendar')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('event_id')
                            ->relationship('event', 'title')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Event'),
                        
                        Forms\Components\TextInput::make('name')
                            ->maxLength(255)
                            ->placeholder('e.g., Day 1, Evening Show, Matinee')
                            ->label('Date Name (Optional)'),
                        
                        Forms\Components\DateTimePicker::make('start_at')
                            ->required()
                            ->seconds(false)
                            ->label('Start Date & Time'),
                        
                        Forms\Components\DateTimePicker::make('end_at')
                            ->seconds(false)
                            ->label('End Date & Time'),
                        
                        Forms\Components\Select::make('status')
                            ->options([
                                'scheduled' => 'Scheduled',
                                'completed' => 'Completed',
                                'cancelled' => 'Cancelled',
                                'postponed' => 'Postponed',
                            ])
                            ->default('scheduled')
                            ->required()
                            ->label('Status'),
                        
                        Forms\Components\TextInput::make('capacity')
                            ->numeric()
                            ->minValue(1)
                            ->label('Capacity (Optional)'),
                        
                        Forms\Components\Toggle::make('is_sold_out')
                            ->label('Sold Out'),
                        
                        Forms\Components\DateTimePicker::make('sales_start_at')
                            ->seconds(false)
                            ->label('Sales Start (Optional)'),
                        
                        Forms\Components\DateTimePicker::make('sales_end_at')
                            ->seconds(false)
                            ->label('Sales End (Optional)'),
                        
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull()
                            ->label('Internal Notes'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event.title')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->label('Event'),
                
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable()
                    ->label('Date Name'),
                
                TextColumn::make('start_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->label('Start'),
                
                TextColumn::make('end_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable()
                    ->label('End'),
                
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'scheduled',
                        'primary' => 'completed',
                        'danger' => 'cancelled',
                        'warning' => 'postponed',
                    ])
                    ->label('Status'),
                
                BadgeColumn::make('is_sold_out')
                    ->colors([
                        'danger' => true,
                        'success' => false,
                    ])
                    ->formatStateUsing(fn ($state): string => $state ? 'Sold Out' : 'Available')
                    ->label('Availability'),
                
                TextColumn::make('capacity')
                    ->numeric()
                    ->toggleable()
                    ->label('Capacity'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'scheduled' => 'Scheduled',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'postponed' => 'Postponed',
                    ]),
                
                SelectFilter::make('event')
                    ->relationship('event', 'title')
                    ->label('Filter by Event'),
                
                Tables\Filters\TernaryFilter::make('is_sold_out')
                    ->label('Sold Out Status'),
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
            ->defaultSort('start_at', 'asc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => EventDateResource\Pages\ListEventDates::route('/'),
            'create' => EventDateResource\Pages\CreateEventDate::route('/create'),
            'edit' => EventDateResource\Pages\EditEventDate::route('/{record}/edit'),
        ];
    }
}
