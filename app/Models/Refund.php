<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Refund extends Model { protected $table = 'refunds'; protected $fillable = ['order_id', 'payment_id', 'amount', 'currency', 'reason', 'status', 'provider_refund_id', 'completed_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }