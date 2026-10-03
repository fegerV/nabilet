<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model { protected $table = 'payments'; protected $fillable = ['order_id', 'provider', 'provider_payment_id', 'amount', 'currency', 'status', 'payment_url', 'idempotency_key', 'metadata_json', 'paid_at'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }