<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SeatHold extends Model { protected $table = 'seat_holds'; protected $fillable = ['inventory_item_id', 'session_id', 'cart_id', 'quantity', 'expires_at', 'released_at', 'converted_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }