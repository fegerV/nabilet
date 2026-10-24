<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id         CHAR(26), ULID base32
 * @property int $order_id             NOT NULL по схеме
 * @property int $payment_id
 * @property int $amount               integer minor units
 * @property string $currency
 * @property string|null $reason
 * @property string $status            из ck_refunds_status (processing|succeeded|failed...)
 * @property string|null $provider_refund_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Refund extends Model
{
    use HasPublicId;

    protected $table = 'refunds';

    protected $fillable = [
        'public_id',
        'order_id',
        'payment_id',
        'amount',
        'currency',
        'reason',
        'status',
        'provider_refund_id',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Orders\Models\Order::class);
    }
}