<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Cart extends Model { protected $table = 'carts'; protected $fillable = ['user_id', 'session_id', 'status', 'expires_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }