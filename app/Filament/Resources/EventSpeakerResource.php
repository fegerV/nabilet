<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\EventSpeaker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;

class EventSpeakerResource extends Resource
{
    protected static ?string $model = EventSpeaker::class;
    protected static ?string $navigationIcon = 'heroicon-o-microphone';
    protected static ?string $navigationGroup = 'Events & Tickets';
    protected static ?string $label = 'Speaker';
    protected static ?string $pluralLabel = 'Speakers';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Speaker Information')
                    ->description('Details about the speaker')
                    ->icon('heroicon-o-user')
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
                            ->label('Full Name'),
                        
                        Forms\Components\TextInput::make('position')
                            ->maxLength(255)
                            ->label('Position/Title')
                            ->placeholder('e.g., CTO @ Company'),
                        
                        Forms\Components\FileUpload::make('avatar')
                            ->image()
                            ->directory('speakers/avatars')
                            ->maxSize(2048)
                            ->circularCropper()
                            ->label('Avatar Photo'),
                        
                        Forms\Components\Toggle::make('is_featured')
                            ->default(false)
                            ->label('Featured Speaker'),
                        
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Sort Order'),
                    ]),
                
                Forms\Components\Section::make('Biography')
                    ->icon('heroicon-o-document-text')
                    ->columns(1)
                    ->schema([
                        Forms\Components\RichEditor::make('bio')
                            ->label('Biography')
                            ->columnSpanFull(),
                    ]),
                
                Forms\Components\Section::make('Social Links')
                    ->description('Optional social media profiles')
                    ->icon('heroicon-o-share')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        Forms\Components\KeyValue::make('social_links')
                            ->keyLabel('Platform')
                            ->valueLabel('Username/URL')
                            ->addActionLabel('Add Social Link')
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
                
                ImageColumn::make('avatar')
                    ->circular()
                    ->label('Avatar'),
                
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Name'),
                
                TextColumn::make('position')
                    ->searchable()
                    ->limit(30)
                    ->label('Position'),
                
                TextColumn::make('event.title')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->label('Event'),
                
                IconColumn::make('is_featured')
                    ->boolean()
                    ->label('Featured'),
                
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
                
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label('Featured Only'),
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
            ->defaultSort('sort_order', 'asc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\EventSpeakerResource\Pages\ListEventSpeakers::route('/'),
            'create' => \App\Filament\Resources\EventSpeakerResource\Pages\CreateEventSpeaker::route('/create'),
            'edit' => \App\Filament\Resources\EventSpeakerResource\Pages\EditEventSpeaker::route('/{record}/edit'),
        ];
    }
}
