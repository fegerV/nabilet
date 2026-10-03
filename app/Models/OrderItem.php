<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $order_id
 * @property int $inventory_item_id
 * @property int $quantity
 * @property int $unit_price
 * @property int $discount_amount
 * @property int $fee_amount
 * @property int $total_amount
 * @property string $event_title_snapshot
 * @property string|null $session_title_snapshot
 * @property string|null $venue_title_snapshot
 * @property array|null $seat_snapshot_json
 * @property \Carbon\Carbon $created_at
 */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'fee_amount',
        'total_amount',
        'event_title_snapshot',
        'session_title_snapshot',
        'venue_title_snapshot',
        'seat_snapshot_json',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'discount_amount' => 'integer',
        'fee_amount' => 'integer',
        'total_amount' => 'integer',
        'seat_snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'order_item_id');
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
