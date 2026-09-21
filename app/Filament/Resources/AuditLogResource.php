<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\AuditLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'AuditLog';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('user_id'),
            Forms\Components\TextInput::make('action'),
            Forms\Components\TextInput::make('entity_type'),
            Forms\Components\TextInput::make('entity_id'),
            Forms\Components\RichEditor::make('old_values_json'),
            Forms\Components\RichEditor::make('new_values_json'),
            Forms\Components\TextInput::make('ip_address'),
            Forms\Components\TextInput::make('user_agent'),
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
            'index' => AuditLogResource\Pages\ListAuditLogs::route('/'),
            'create' => AuditLogResource\Pages\CreateAuditLog::route('/create'),
            'edit' => AuditLogResource\Pages\EditAuditLog::route('/{record}/edit'),
        ];
    }
}
