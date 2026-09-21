<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $payment_id
 * @property string $reason
 * @property string $status 'pending' | 'completed' | 'failed'
 * @property string $amount
 * @property string $currency
 * @property \Carbon\CarbonImmutable $created_at
 */
class Refund extends Model
{
    protected $table = 'refunds';

    protected $fillable = [
        'public_id',
        'payment_id',
        'reason',
        'status',
        'amount',
        'currency',
    ];

    protected $casts = [
        'amount' => 'string',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
