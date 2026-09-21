<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * StandingZone Model
 */
class StandingZone extends Model
{
    protected $table = 'standing_zones';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'sector_id',
        'name',
        'capacity',
        'price_amount',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'price_amount' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Inventory\Models\InventoryItem::class, 'standing_zone_id');
    }
}
