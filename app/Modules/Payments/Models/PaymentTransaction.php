<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property string $type 'charge' | 'refund' | 'capture'
 * @property string $status 'pending' | 'completed' | 'failed'
 * @property string $amount
 * @property string $currency
 * @property string|null $provider_response
 * @property \Carbon\CarbonImmutable $created_at
 */
class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';

    protected $fillable = [
        'payment_id',
        'type',
        'status',
        'amount',
        'currency',
        'provider_response',
    ];

    protected $casts = [
        'amount' => 'string',
        'provider_response' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
