<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Events\Models\Session;
use Nabilet\Modules\Venues\Models\Seat;
use Nabilet\Modules\Venues\Models\StandingZone;
use Illuminate\Support\Str;

/**
 * InventoryItem — продаваемое место/зона на сессию.
 * Реальная схема (inventory_items): price_amount BIGINT, currency char(3),
 * capacity/available_quantity INT, status, metadata_json, public_id NOT NULL.
 */
class InventoryItem extends Model
{
    protected $table = 'inventory_items';

    protected $fillable = [
        'public_id',
        'session_id',
        'seat_id',
        'standing_zone_id',
        'type',
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
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if ($model->public_id === null) {
                $model->public_id = (string) Str::ulid()->toBase32();
            }
            if ($model->currency === null) {
                $model->currency = 'RUB';
            }
            if ($model->capacity === null) {
                $model->capacity = 1;
            }
            if ($model->available_quantity === null) {
                $model->available_quantity = $model->capacity;
            }
            if ($model->status === null) {
                $model->status = 'available';
            }
        });
    }

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