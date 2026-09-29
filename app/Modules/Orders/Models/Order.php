<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Events\Models\Event;

/**
 * @property int $id
 * @property string $public_id      CHAR(26), ULID base32
 * @property string $order_number   NB-YYYYMMDD-XXXXXXXX
 * @property int $organization_id
 * @property int|null $user_id
 * @property int|null $session_id   сеанс заказа (резолвится из корзины при checkout)
 * @property int|null $event_id     событие заказа (резолвится из сеанса)
 * @property int $subtotal_amount   integer minor units
 * @property int $discount_amount
 * @property int $fee_amount
 * @property int $total_amount
 * @property string $currency
 * @property string $status         'pending' | 'awaiting_payment' | 'paid' | 'cancelled' | ...
 * @property string $payment_status 'pending' | 'succeeded' ...
 * @property string $customer_email
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property int|null $promo_code_id
 * @property int|null $cart_id
 */
class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'public_id',
        'order_number',
        'organization_id',
        'user_id',
        'session_id',
        'event_id',
        'subtotal_amount',
        'discount_amount',
        'fee_amount',
        'total_amount',
        'currency',
        'status',
        'payment_status',
        'customer_email',
        'customer_name',
        'customer_phone',
        'paid_at',
        'cancelled_at',
        'promo_code_id',
        'cart_id',
    ];

    protected $casts = [
        'subtotal_amount' => 'integer',
        'discount_amount' => 'integer',
        'fee_amount' => 'integer',
        'total_amount' => 'integer',
        'session_id' => 'integer',
        'event_id' => 'integer',
        'cart_id' => 'integer',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // CHAR(26) public_id обязателен везде в этой схеме — генерируем ULID base32.
        static::creating(function (Order $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
            if ($model->order_number === null) {
                $model->order_number = 'NB-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}