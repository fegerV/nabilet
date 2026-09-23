<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property string|null $provider_event_id
 * @property string $type
 * @property int|null $amount
 * @property string|null $currency
 * @property string|null $status
 * @property array|null $payload_json
 * @property \Carbon\Carbon $created_at
 */
class PaymentTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'provider_event_id',
        'type',
        'amount',
        'currency',
        'status',
        'payload_json',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
