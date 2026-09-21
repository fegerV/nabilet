<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Ticket extends Model { protected $table = 'tickets'; protected $fillable = ['ticket_number', 'ticket_index', 'order_id', 'order_item_id', 'event_id', 'session_id', 'inventory_item_id', 'seat_id', 'standing_zone_id', 'holder_name', 'status', 'qr_version', 'qr_token_hash', 'issued_at', 'used_at', 'cancelled_at', 'refunded_at', 'expired_at', 'revoked_at', 'revoked_reason'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }