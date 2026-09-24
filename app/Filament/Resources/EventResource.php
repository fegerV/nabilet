<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;

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
                                                    ->label('Event Title')
                                                    ->hint('Название показывается на афише и в билете. Пишите так, как это увидит покупатель: «Симфонический оркестр: Вивальди и Пьяццолла».')
                                                    ->hintIcon('heroicon-o-information-circle'),
                                                Forms\Components\TextInput::make('slug')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->unique(ignoreRecord: true)
                                                    ->label('Slug (URL-friendly name)')
                                                    ->hint('Часть адреса страницы: только латиница, строчные, дефис вместо пробелов (например: simfonicheskiy-orkestr). Менять после публикации нельзя.')
                                                    ->hintIcon('heroicon-o-link'),
                                                Forms\Components\Select::make('category_id')
                                                    ->relationship('category', 'name')
                                                    ->searchable()
                                                    ->preload()
                                                    ->label('Category')
                                                    ->hint('Раздел витрины: Классика, Стендап, Детям и т.д. Нужная категория создаётся заранее.')
                                                    ->hintIcon('heroicon-o-tag'),
                                                Forms\Components\TextInput::make('age_limit')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->suffix('+')
                                                    ->label('Age Limit')
                                                    ->hint('Возрастное ограничение: 0+, 6+, 12+, 16+, 18+. Влияет на допуск и заметку на карточке.')
                                                    ->hintIcon('heroicon-o-shield-check'),
                                                Forms\Components\TextInput::make('duration_minutes')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->suffix('min')
                                                    ->label('Duration')
                                                    ->hint('Продолжительность в минутах, включая антракт. Зависит от программы.')
                                                    ->hintIcon('heroicon-o-clock'),
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
                            ->label('Status')
                                                        ->hint('Published — карточка на витрине и открытые продажи. Draft — скрыт, изменения безопасны.')
                                                        ->hintIcon('heroicon-o-check-circle'),
                                                    Forms\Components\DateTimePicker::make('published_at')
                                                        ->label('Published At')
                                                        ->hint('Момент выхода на витрину. Оставьте пустым — возьмётся момент нажатия «Published».')
                                                        ->hintIcon('heroicon-o-calendar-days'),
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
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->label('Title'),
                ImageColumn::make('poster')
                    ->circular()
                    ->label('Poster'),
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'published',
                        'warning' => 'draft',
                        'danger' => 'cancelled',
                        'gray' => 'archived',
                    ])
                    ->label('Status'),
                TextColumn::make('published_at')
                    ->date()
                    ->sortable()
                    ->label('Published'),
                TextColumn::make('created_at')
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
    

}
