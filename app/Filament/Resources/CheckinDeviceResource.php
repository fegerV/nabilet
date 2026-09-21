<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\CheckinDevice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CheckinDeviceResource extends Resource
{
    protected static ?string $model = CheckinDevice::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'CheckinDevice';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name'),
            Forms\Components\TextInput::make('device_token_hash'),
            Forms\Components\Select::make('platform'),
            Forms\Components\TextInput::make('app_version'),
            Forms\Components\Select::make('status'),
            Forms\Components\DateTimePicker::make('last_seen_at'),
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
            'index' => CheckinDeviceResource\Pages\ListCheckinDevices::route('/'),
            'create' => CheckinDeviceResource\Pages\CreateCheckinDevice::route('/create'),
            'edit' => CheckinDeviceResource\Pages\EditCheckinDevice::route('/{record}/edit'),
        ];
    }
}
