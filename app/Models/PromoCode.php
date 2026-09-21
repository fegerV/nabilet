<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromoCode extends Model { protected $table = 'promo_codes'; protected $fillable = ['organization_id', 'code', 'discount_type', 'value_amount', 'value_percent', 'currency', 'scope', 'event_id', 'event_category_id', 'min_order_amount', 'max_redemptions', 'per_user_limit', 'redemptions_count', 'status', 'valid_from', 'valid_until'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    } }