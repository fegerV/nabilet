<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\EventFaq;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;

class EventFaqResource extends Resource
{
    protected static ?string $model = EventFaq::class;
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static ?string $navigationGroup = 'Events & Tickets';
    protected static ?string $label = 'FAQ';
    protected static ?string $pluralLabel = 'FAQs';
    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('FAQ Information')
                    ->description('Frequently asked question')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('event_id')
                            ->relationship('event', 'title')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Event'),
                        
                        Forms\Components\TextInput::make('question')
                            ->required()
                            ->maxLength(500)
                            ->label('Question'),
                        
                        Forms\Components\Select::make('category')
                            ->options([
                                'ticket' => 'Tickets',
                                'venue' => 'Venue',
                                'payment' => 'Payment',
                                'general' => 'General',
                                'accessibility' => 'Accessibility',
                            ])
                            ->default('general')
                            ->required()
                            ->label('Category'),
                        
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->label('Active'),
                        
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Sort Order'),
                    ]),
                
                Forms\Components\Section::make('Answer')
                    ->icon('heroicon-o-document-text')
                    ->columns(1)
                    ->schema([
                        Forms\Components\RichEditor::make('answer')
                            ->label('Answer')
                            ->required()
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
                
                TextColumn::make('question')
                    ->searchable()
                    ->limit(50)
                    ->label('Question'),
                
                TextColumn::make('category')
                    ->badge()
                    ->colors([
                        'blue' => 'ticket',
                        'green' => 'venue',
                        'orange' => 'payment',
                        'gray' => 'general',
                        'purple' => 'accessibility',
                    ])
                    ->label('Category'),
                
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
                
                SelectFilter::make('category')
                    ->options([
                        'ticket' => 'Tickets',
                        'venue' => 'Venue',
                        'payment' => 'Payment',
                        'general' => 'General',
                        'accessibility' => 'Accessibility',
                    ])
                    ->label('Filter by Category'),
                
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
            ->defaultSort('category', 'asc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\EventFaqResource\Pages\ListEventFaqs::route('/'),
            'create' => \App\Filament\Resources\EventFaqResource\Pages\CreateEventFaq::route('/create'),
            'edit' => \App\Filament\Resources\EventFaqResource\Pages\EditEventFaq::route('/{record}/edit'),
        ];
    }
}
