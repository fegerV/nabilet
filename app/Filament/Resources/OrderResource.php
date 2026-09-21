<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Order';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('order_number'),
            Forms\Components\TextInput::make('user_id'),
            Forms\Components\TextInput::make('subtotal_amount')->numeric(),
            Forms\Components\TextInput::make('discount_amount')->numeric(),
            Forms\Components\TextInput::make('fee_amount')->numeric(),
            Forms\Components\TextInput::make('total_amount')->numeric(),
            Forms\Components\Select::make('currency'),
            Forms\Components\Select::make('status'),
            Forms\Components\TextInput::make('payment_status'),
            Forms\Components\TextInput::make('customer_email')->email(),
            Forms\Components\TextInput::make('customer_phone'),
            Forms\Components\DateTimePicker::make('paid_at'),
            Forms\Components\DateTimePicker::make('cancelled_at'),
            Forms\Components\TextInput::make('promo_code_id'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => OrderResource\Pages\ListOrders::route('/'),
            'create' => OrderResource\Pages\CreateOrder::route('/create'),
            'edit' => OrderResource\Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
