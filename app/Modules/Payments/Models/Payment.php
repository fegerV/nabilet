<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Core\Tenancy\TenantScoped;

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
 * `organization_id` добавлен фазой 1 (nullable); method/webhook_url/succeeded_at/failure_* НЕТ.
 */
class Payment extends Model
{
    use HasPublicId;
    use TenantScoped;

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
        // `public_id` заполняет трейт HasPublicId; здесь — ключ идемпотентности,
        // который защищает от двойного списания при повторном запросе.
        static::creating(function (Payment $model): void {
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