<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property string|null $provider_event_id  UNIQUE (payment_id, provider_event_id)
 * @property string $type                    'authorization' | 'capture' | 'refund' | 'failure'
 * @property int|null $amount
 * @property string|null $currency
 * @property string|null $status
 * @property array|null $payload_json
 * @property \Carbon\CarbonImmutable $created_at
 *
 * В таблице НЕТ updated_at — модель не должна его писать (как OrderItem).
 */
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'payment_id',
        'provider_event_id',
        'type',
        'amount',
        'currency',
        'status',
        'payload_json',
    ];

    protected $casts = [
        'amount' => 'integer',
        'payload_json' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}