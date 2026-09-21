<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Orders\Models\Order;

/**
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property string $provider 'stripe' | 'paypal' | 'kaspi'
 * @property string $status 'pending' | 'completed' | 'failed' | 'refunded'
 * @property string $amount
 * @property string $currency
 * @property string|null $transaction_id
 * @property string|null $metadata
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'public_id',
        'order_id',
        'provider',
        'status',
        'amount',
        'currency',
        'transaction_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'string',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
