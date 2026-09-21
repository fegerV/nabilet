<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Inventory\Models\InventoryItem;

/**
 * SeatHold Model - Database Persistence Layer
 * 
 * @property int $id
 * @property string $public_id
 * @property int $inventory_item_id
 * @property int $quantity
 * @property string $expires_at
 * @property string $status 'active' | 'released' | 'converted'
 * @property \Carbon\CarbonImmutable $created_at
 */
class SeatHold extends Model
{
    protected $table = 'seat_holds';

    protected $fillable = [
        'public_id',
        'inventory_item_id',
        'quantity',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
