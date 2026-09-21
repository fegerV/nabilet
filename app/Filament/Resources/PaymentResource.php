<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Payment';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('order_id'),
            Forms\Components\TextInput::make('provider'),
            Forms\Components\TextInput::make('provider_payment_id'),
            Forms\Components\TextInput::make('amount')->numeric(),
            Forms\Components\Select::make('currency'),
            Forms\Components\Select::make('status'),
            Forms\Components\TextInput::make('payment_url')->url(),
            Forms\Components\TextInput::make('idempotency_key'),
            Forms\Components\RichEditor::make('metadata_json'),
            Forms\Components\DateTimePicker::make('paid_at'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => PaymentResource\Pages\ListPayments::route('/'),
            'create' => PaymentResource\Pages\CreatePayment::route('/create'),
            'edit' => PaymentResource\Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
