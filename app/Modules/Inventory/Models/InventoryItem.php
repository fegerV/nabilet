<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Events\Models\Session;
use Nabilet\Modules\Venues\Models\Seat;
use Nabilet\Modules\Venues\Models\StandingZone;

/**
 * @property int $id
 * @property int $session_id
 * @property int|null $seat_id
 * @property int|null $standing_zone_id
 * @property string $type 'seat' | 'standing'
 * @property int $quantity
 * @property int $available_quantity
 * @property string $status 'available' | 'locked' | 'sold_out'
 * @property string $price
 * @property string $currency
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class InventoryItem extends Model
{
    protected $table = 'inventory_items';

    protected $fillable = [
        'session_id',
        'seat_id',
        'standing_zone_id',
        'type',
        'quantity',
        'available_quantity',
        'status',
        'price',
        'currency',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'available_quantity' => 'integer',
        'price' => 'string',
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
}
