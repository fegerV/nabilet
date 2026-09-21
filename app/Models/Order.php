<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Order extends Model { protected $table = 'orders'; protected $fillable = ['order_number', 'user_id', 'organization_id', 'subtotal_amount', 'discount_amount', 'fee_amount', 'total_amount', 'currency', 'status', 'payment_status', 'customer_email', 'customer_phone', 'paid_at', 'cancelled_at', 'promo_code_id'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }