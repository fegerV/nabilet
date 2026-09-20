<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session Model (Event Session)
 */
class Session extends Model
{
    protected $table = 'sessions';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'event_id',
        'venue_id',
        'hall_id',
        'schema_version_id',
        'starts_at',
        'ends_at',
        'sales_start_at',
        'sales_end_at',
        'timezone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime:Y-m-d H:i:s.u',
            'ends_at' => 'datetime:Y-m-d H:i:s.u',
            'sales_start_at' => 'datetime:Y-m-d H:i:s.u',
            'sales_end_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Events\Models\Event::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Venues\Models\Venue::class);
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Venues\Models\Hall::class);
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Venues\Models\HallSchemaVersion::class, 'schema_version_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\App\Modules\Inventory\Models\InventoryItem::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(\App\Modules\Carts\Models\Cart::class);
    }

    public function seatHolds(): HasMany
    {
        return $this->hasMany(\App\Modules\Orders\Models\SeatHold::class);
    }

    public function ticketScans(): HasMany
    {
        return $this->hasMany(\App\Modules\Tickets\Models\TicketScan::class);
    }
}
