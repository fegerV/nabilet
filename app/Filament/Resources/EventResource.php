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
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Events & Tickets';
    protected static ?string $label = 'Event';
    protected static ?string $pluralLabel = 'Events';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->description('Core details about the event')
                    ->icon('heroicon-o-information-circle')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->label('Event Title'),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->label('Slug (URL-friendly name)'),
                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Category'),
                        Forms\Components\TextInput::make('age_limit')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('+')
                            ->label('Age Limit'),
                        Forms\Components\TextInput::make('duration_minutes')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('min')
                            ->label('Duration'),
                    ]),
                
                Forms\Components\Section::make('Description')
                    ->icon('heroicon-o-document-text')
                    ->columns(1)
                    ->schema([
                        Forms\Components\RichEditor::make('short_description')
                            ->label('Short Description')
                            ->columnSpanFull(),
                        Forms\Components\RichEditor::make('description')
                            ->label('Full Description')
                            ->columnSpanFull(),
                    ]),
                
                Forms\Components\Section::make('Media')
                    ->icon('heroicon-o-photo')
                    ->columns(2)
                    ->schema([
                        Forms\Components\FileUpload::make('poster')
                            ->image()
                            ->directory('events/posters')
                            ->maxSize(5120)
                            ->label('Poster Image'),
                        Forms\Components\FileUpload::make('cover')
                            ->image()
                            ->directory('events/covers')
                            ->maxSize(5120)
                            ->label('Cover Image'),
                    ]),
                
                Forms\Components\Section::make('SEO Settings')
                    ->description('Search engine optimization settings')
                    ->icon('heroicon-o-globe-alt')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('seo_title')
                            ->maxLength(60)
                            ->label('SEO Title'),
                        Forms\Components\TextInput::make('seo_description')
                            ->maxLength(160)
                            ->label('SEO Description'),
                        Forms\Components\TextInput::make('canonical_url')
                            ->url()
                            ->label('Canonical URL'),
                        Forms\Components\TextInput::make('robots')
                            ->placeholder('index, follow')
                            ->label('Robots Meta'),
                    ]),
                
                Forms\Components\Section::make('Status & Publishing')
                    ->icon('heroicon-o-check-circle')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'published' => 'Published',
                                'archived' => 'Archived',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('draft')
                            ->required()
                            ->label('Status'),
                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Published At'),
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
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->label('Title'),
                Tables\Columns\ImageColumn::make('poster')
                    ->circular()
                    ->label('Poster'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'published',
                        'warning' => 'draft',
                        'danger' => 'cancelled',
                        'gray' => 'archived',
                    ])
                    ->label('Status'),
                Tables\Columns\TextColumn::make('published_at')
                    ->date()
                    ->sortable()
                    ->label('Published'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Created At'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->label('Filter by Category'),
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
            'index' => EventResource\Pages\ListEvents::route('/'),
            'create' => EventResource\Pages\CreateEvent::route('/create'),
            'edit' => EventResource\Pages\EditEvent::route('/{record}/edit'),
        ];
    }
    
    public static function canViewAny($record): bool
    {
        return true;
    }
}
