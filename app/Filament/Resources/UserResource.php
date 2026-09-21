<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'User';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')->email(),
            Forms\Components\DateTimePicker::make('email_verified_at'),
            Forms\Components\TextInput::make('first_name'),
            Forms\Components\TextInput::make('last_name'),
            Forms\Components\TextInput::make('phone'),
            Forms\Components\DateTimePicker::make('phone_verified_at'),
            Forms\Components\Select::make('status'),
            Forms\Components\Select::make('locale'),
            Forms\Components\Select::make('timezone'),
            Forms\Components\DateTimePicker::make('last_login_at'),
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
            'index' => UserResource\Pages\ListUsers::route('/'),
            'create' => UserResource\Pages\CreateUser::route('/create'),
            'edit' => UserResource\Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
