<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Webhook;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebhookResource extends Resource
{
    protected static ?string $model = Webhook::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Webhook';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
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
            'index' => WebhookResource\Pages\ListWebhooks::route('/'),
            'create' => WebhookResource\Pages\CreateWebhook::route('/create'),
            'edit' => WebhookResource\Pages\EditWebhook::route('/{record}/edit'),
        ];
    }
}
