<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $promo_code_id
 * @property string $order_id
 * @property string $discount_amount
 * @property \Carbon\CarbonImmutable $created_at
 */
class PromoCodeRedemption extends Model
{
    protected $table = 'promo_code_redemptions';

    protected $fillable = [
        'promo_code_id',
        'order_id',
        'discount_amount',
    ];

    protected $casts = [
        'discount_amount' => 'string',
    ];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
