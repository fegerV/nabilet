<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Models\Ticket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Resources';
    protected static ?string $label = 'Ticket';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('ticket_number'),
            Forms\Components\TextInput::make('ticket_index'),
            Forms\Components\TextInput::make('order_id'),
            Forms\Components\TextInput::make('order_item_id'),
            Forms\Components\TextInput::make('event_id'),
            Forms\Components\TextInput::make('session_id'),
            Forms\Components\TextInput::make('inventory_item_id'),
            Forms\Components\TextInput::make('seat_id'),
            Forms\Components\TextInput::make('standing_zone_id'),
            Forms\Components\TextInput::make('holder_name'),
            Forms\Components\Select::make('status'),
            Forms\Components\TextInput::make('qr_version'),
            Forms\Components\TextInput::make('qr_token_hash'),
            Forms\Components\DateTimePicker::make('issued_at'),
            Forms\Components\DateTimePicker::make('used_at'),
            Forms\Components\DateTimePicker::make('cancelled_at'),
            Forms\Components\DateTimePicker::make('refunded_at'),
            Forms\Components\DateTimePicker::make('expired_at'),
            Forms\Components\DateTimePicker::make('revoked_at'),
            Forms\Components\TextInput::make('revoked_reason'),
        ]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array
    {
        return [
            'index' => TicketResource\Pages\ListTickets::route('/'),
            'create' => TicketResource\Pages\CreateTicket::route('/create'),
            'edit' => TicketResource\Pages\EditTicket::route('/{record}/edit'),
        ];
    }
}
