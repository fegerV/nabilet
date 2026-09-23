<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Nabilet\Modules\Orders\Models\Order;

/**
 * @property int $id
 * @property string $public_id       CHAR(26), ULID base32
 * @property int $order_id           NOT NULL по схеме
 * @property string $provider        'yookassa' | 'stripe' | ...
 * @property string|null $provider_payment_id
 * @property int $amount             integer minor units
 * @property string $currency
 * @property string $status          из ck_payments_status: pending|waiting_for_capture|succeeded|canceled|failed
 * @property string|null $payment_url
 * @property string $idempotency_key NOT NULL по схеме
 * @property array|null $metadata_json
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property \Carbon\CarbonImmutable $updated_at
 *
 * Колонок organization_id/method/webhook_url/succeeded_at/failure_* в payments НЕТ.
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'public_id',
        'order_id',
        'provider',
        'provider_payment_id',
        'amount',
        'currency',
        'status',
        'payment_url',
        'idempotency_key',
        'metadata_json',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata_json' => 'array',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // CHAR(26) public_id обязателен везде в этой схеме.
        static::creating(function (Payment $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
            if ($model->idempotency_key === null) {
                $model->idempotency_key = (string) Str::ulid();
            }
        });
    }

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