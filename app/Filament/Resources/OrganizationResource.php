<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Organization;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Organization';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name'),
            Forms\Components\TextInput::make('slug'),
            Forms\Components\RichEditor::make('description'),
            Forms\Components\TextInput::make('logo')->url(),
            Forms\Components\TextInput::make('email')->email(),
            Forms\Components\TextInput::make('phone'),
            Forms\Components\Select::make('status'),
            Forms\Components\RichEditor::make('settings_json'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => OrganizationResource\Pages\ListOrganizations::route('/'),
            'create' => OrganizationResource\Pages\CreateOrganization::route('/create'),
            'edit' => OrganizationResource\Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
