<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $session_id
 * @property string $type
 * @property int|null $seat_id
 * @property int|null $standing_zone_id
 * @property int $price_amount
 * @property string $currency
 * @property int $capacity
 * @property int $available_quantity
 * @property string $status
 * @property array|null $metadata_json
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class InventoryItem extends Model
{
    protected $fillable = [
        'public_id',
        'session_id',
        'type',
        'seat_id',
        'standing_zone_id',
        'price_amount',
        'currency',
        'capacity',
        'available_quantity',
        'status',
        'metadata_json',
    ];

    protected $casts = [
        'price_amount' => 'integer',
        'capacity' => 'integer',
        'available_quantity' => 'integer',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function standingZone(): BelongsTo
    {
        return $this->belongsTo(StandingZone::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'inventory_item_id');
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class, 'inventory_item_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'inventory_item_id');
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
