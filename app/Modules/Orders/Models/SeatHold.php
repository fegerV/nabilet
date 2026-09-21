<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Cart\Models\Cart;

/**
 * SeatHold Model - Database Persistence Layer
 * 
 * @property int $id
 * @property string $public_id
 * @property int $inventory_item_id
 * @property int $cart_id
 * @property int $session_id
 * @property int $quantity
 * @property string $expires_at
 * @property string|null $released_at
 * @property string|null $converted_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class SeatHold extends Model
{
    protected $table = 'seat_holds';

    protected $fillable = [
        'public_id',
        'inventory_item_id',
        'cart_id',
        'session_id',
        'quantity',
        'expires_at',
        'released_at',
        'converted_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Check if hold is still active (not released or converted)
     */
    public function isActive(): bool
    {
        return $this->released_at === null && $this->converted_at === null;
    }

    /**
     * Check if hold has expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if hold can be converted to order
     */
    public function isConvertible(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        // Allow grace period of 5 minutes after expiry for payment completion
        $gracePeriod = $this->expires_at->copy()->addMinutes(5);
        return $this->expires_at->isFuture() || now()->lt($gracePeriod);
    }
}
